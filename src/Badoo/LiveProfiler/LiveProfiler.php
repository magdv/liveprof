<?php

/**
 * @maintainer Timur Shagiakhmetov <timur.shagiakhmetov@corp.badoo.com>
 */

namespace Badoo\LiveProfiler;

use b;
use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Throwable;

class LiveProfiler
{
    CONST MODE_DB = 'db';
    CONST MODE_FILES = 'files';
    CONST MODE_API = 'api';

    protected static ?LiveProfiler $instance;

    protected string $mode = self::MODE_DB;

    protected string $path = '';

    protected string $api_key = '';

    protected string $url = 'http://liveprof.org/api';

    protected ?Connection $Conn;

    protected LoggerInterface $Logger;

    protected DataPackerInterface $DataPacker;

    protected string $connection_string;

    protected string $app;

    protected string $label;

    protected string $datetime;

    protected int $divider = 1000;

    protected int $total_divider = 10000;

    protected ?Closure $start_callback;

    protected ?Closure $end_callback;

    protected bool $is_enabled = false;

    protected array $last_profile_data = [];

    /**
     * LiveProfiler constructor.
     */
    public function __construct(string $connection_string_or_path = '', string $mode = self::MODE_DB)
    {
        $this->mode = $mode;

        $this->app = 'Default';
        $this->label = $this->getAutoLabel();
        $this->datetime = date('Y-m-d H:i:s');

        $this->detectProfiler();
        $this->Logger = new Logger();
        $this->DataPacker = new DataPacker();

        if ($mode === self::MODE_DB) {
            $this->connection_string = $connection_string_or_path ?: getenv('LIVE_PROFILER_CONNECTION_URL');
        } elseif ($mode === self::MODE_API) {
            if ($connection_string_or_path) {
                $this->url = $connection_string_or_path;
            } elseif (getenv('LIVE_PROFILER_API_URL')) {
                $this->url = getenv('LIVE_PROFILER_API_URL');
            }
        } else {
            $this->setPath($connection_string_or_path ?: getenv('LIVE_PROFILER_PATH'));
        }
    }

    public static function getInstance($connection_string = '', $mode = self::MODE_DB): self
    {
        if (self::$instance === null) {
            self::$instance = new static($connection_string, $mode);
        }

        return self::$instance;
    }

    public function start(): bool
    {
        if ($this->is_enabled) {
            return true;
        }

        if (null === $this->start_callback) {
            return true;
        }

        if ($this->needToStart($this->divider)) {
            $this->is_enabled = true;
        } elseif ($this->needToStart($this->total_divider)) {
            $this->is_enabled = true;
            $this->label = 'All';
        }

        if ($this->is_enabled) {
            register_shutdown_function([$this, 'end']);
            call_user_func($this->start_callback);
        }

        return true;
    }

    /**
     * @return bool
     */
    public function end(): bool
    {
        if (!$this->is_enabled) {
            return true;
        }

        $this->is_enabled = false;

        if (null === $this->end_callback) {
            return true;
        }

        $data = call_user_func($this->end_callback);
        if (!is_array($data)) {
            $this->Logger->warning('Invalid profiler data: ' . var_export($data, true));
            return false;
        }

        if (empty($data)) {
            return false;
        }

        $this->last_profile_data = $data;
        $result = $this->save($this->app, $this->label, $this->datetime, $data);

        if (!$result) {
            $this->Logger->warning('Can\'t insert profile data');
        }

        return $result;
    }

    public function detectProfiler(): self
    {
        if (function_exists('xhprof_enable')) {
            return $this->useXhprof();
        }

        if (function_exists('tideways_xhprof_enable')) {
            return $this->useTidyWays();
        }

        if (function_exists('uprofiler_enable')) {
            return $this->useUprofiler();
        }

        return $this->useSimpleProfiler();
    }

    public function useXhprof(): self
    {
        if ($this->is_enabled) {
            $this->Logger->warning('can\'t change profiler after profiling started');
            return $this;
        }

        $this->start_callback = function () {
            xhprof_enable(XHPROF_FLAGS_MEMORY | XHPROF_FLAGS_CPU);
        };

        $this->end_callback = function () {
            return xhprof_disable();
        };

        return $this;
    }

    public function useXhprofSample(): self
    {
        if ($this->is_enabled) {
            $this->Logger->warning('can\'t change profiler after profiling started');
            return $this;
        }

        if (!ini_get('xhprof.sampling_interval')) {
            ini_set('xhprof.sampling_interval', 10000);
        }

        if (!ini_get('xhprof.sampling_depth')) {
            ini_set('xhprof.sampling_depth', 200);
        }

        $this->start_callback = function () {
            define('XHPROF_SAMPLING_BEGIN', microtime(true));
            xhprof_sample_enable();
        };

        $this->end_callback = function () {
            return $this->convertSampleDataToCommonFormat(xhprof_sample_disable());
        };

        return $this;
    }

    protected function convertSampleDataToCommonFormat(array $sampling_data): array
    {
        $result_data = [];
        $prev_time = XHPROF_SAMPLING_BEGIN;
        foreach ($sampling_data as $time => $callstack) {
            $wt = (int)(($time - $prev_time) * 1000000);
            $functions = explode('==>', $callstack);
            $prev_i = 0;
            $main_key = $functions[$prev_i];
            if (!isset($result_data[$main_key])) {
                $result_data[$main_key] = [
                    'ct' => 0,
                    'wt' => 0,
                ];
            }
            $result_data[$main_key]['ct'] ++;
            $result_data[$main_key]['wt'] += $wt;

            $func_cnt = count($functions);
            for ($i = 1; $i < $func_cnt; $i++) {
                $key = $functions[$prev_i] . '==>' . $functions[$i];

                if (!isset($result_data[$key])) {
                    $result_data[$key] = [
                        'ct' => 0,
                        'wt' => 0,
                    ];
                }

                $result_data[$key]['wt'] += $wt;
                $result_data[$key]['ct']++;

                $prev_i = $i;
            }

            $prev_time = $time;
        }

        return $result_data;
    }

    public function useTidyWays(): self
    {
        if ($this->is_enabled) {
            $this->Logger->warning('can\'t change profiler after profiling started');
            return $this;
        }

        $this->start_callback = function () {
            tideways_xhprof_enable(TIDEWAYS_XHPROF_FLAGS_MEMORY | TIDEWAYS_XHPROF_FLAGS_CPU);
        };

        $this->end_callback = function () {
            return tideways_xhprof_disable();
        };

        return $this;
    }

    public function useUprofiler(): self
    {
        if ($this->is_enabled) {
            $this->Logger->warning('can\'t change profiler after profiling started');
            return $this;
        }

        $this->start_callback = function () {
            uprofiler_enable(UPROFILER_FLAGS_CPU | UPROFILER_FLAGS_MEMORY);
        };

        $this->end_callback = function () {
            return uprofiler_disable();
        };

        return $this;
    }

    public function useSimpleProfiler(): self
    {
        if ($this->is_enabled) {
            $this->Logger->warning('can\'t change profiler after profiling started');
            return $this;
        }

        $this->start_callback = function () {
            \Badoo\LiveProfiler\SimpleProfiler::getInstance()->enable();
        };

        $this->end_callback = function () {
            return \Badoo\LiveProfiler\SimpleProfiler::getInstance()->disable();
        };

        return $this;
    }

    public function reset(): bool
    {
        if ($this->is_enabled) {
            call_user_func($this->end_callback);
            $this->is_enabled = false;
        }

        return true;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setPath(string $path): self
    {
        if (!is_dir($path)) {
            $this->Logger->error('Directory ' . $path . ' does not exists');
        }

        $this->path = $path;
        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setApiKey(string $api_key): self
    {
        $this->api_key = $api_key;
        return $this;
    }

    public function getApiKey(): string
    {
        return $this->api_key;
    }

    public function setApp(string $app): self
    {
        $this->app = $app;
        return $this;
    }

    public function getApp(): string
    {
        return $this->app;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setDateTime(string $datetime): self
    {
        $this->datetime = $datetime;
        return $this;
    }

    public function getDateTime(): string
    {
        return $this->datetime;
    }

    public function setDivider(string $divider): self
    {
        $this->divider = $divider;
        return $this;
    }

    public function setTotalDivider(string $total_divider): self
    {
        $this->total_divider = $total_divider;
        return $this;
    }

    public function setStartCallback(\Closure $start_callback): self
    {
        $this->start_callback = $start_callback;
        return $this;
    }

    public function setEndCallback(\Closure $end_callback): self
    {
        $this->end_callback = $end_callback;
        return $this;
    }

    public function setLogger(LoggerInterface $Logger): self
    {
        $this->Logger = $Logger;
        return $this;
    }

    public function setDataPacker($DataPacker): self
    {
        $this->DataPacker = $DataPacker;
        return $this;
    }

    public function getLastProfileData(): array
    {
        return $this->last_profile_data;
    }

    /**
     * @throws Exception
     */
    protected function getConnection(): Connection
    {
        if (null === $this->Conn) {
            $config = new \Doctrine\DBAL\Configuration();
            $connectionParams = ['url' => $this->connection_string];
            $this->Conn = \Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);
        }

        return $this->Conn;
    }

    public function setConnection(Connection $Conn): self
    {
        $this->Conn = $Conn;
        return $this;
    }

    public function setConnectionString(string $connection_string): self
    {
        $this->connection_string = $connection_string;
        return $this;
    }

    protected function save(string $app, string $label, string $datetime, array $data): bool
    {
        if ($this->mode === self::MODE_DB) {
            return $this->saveToDB($app, $label, $datetime, $data);
        }

        if ($this->mode === self::MODE_API) {
            return $this->sendToAPI($app, $label, $datetime, $data);
        }

        return $this->saveToFile($app, $label, $datetime, $data);
    }

    protected function sendToAPI(string $app, string $label, string $datetime, array $data): bool
    {
        $data = $this->DataPacker->pack($data);
        $api_key = $this->api_key;
        $curl_handle = curl_init();
        curl_setopt($curl_handle,CURLOPT_URL,$this->url);
        curl_setopt($curl_handle,CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl_handle, CURLOPT_POST, 1);
        curl_setopt($curl_handle, CURLOPT_POSTFIELDS, http_build_query(compact('api_key', 'app', 'label', 'datetime', 'data')));
        curl_exec($curl_handle);
        $http_code = curl_getinfo($curl_handle, CURLINFO_HTTP_CODE);
        curl_close($curl_handle);

        return $http_code === 200;
    }

    protected function saveToDB(string $app, string $label, string $datetime, array $data): bool
    {
        $packed_data = $this->DataPacker->pack($data);

        try {
            return (bool)$this->getConnection()->insert(
                'details',
                [
                    'app' => $app,
                    'label' => $label,
                    'perfdata' => $packed_data,
                    'timestamp' => $datetime
                ]
            );
        } catch (Throwable $Ex) {
            $this->Logger->error('Error in insertion profile data: ' . $Ex->getMessage());
            return false;
        }
    }

    private function saveToFile(string $app, string $label, string $datetime, array $data): bool
    {
        $path = sprintf('%s/%s/%s', $this->path, $app, base64_encode($label));

        if (!is_dir($path) && !mkdir($path, 0755, true) && !is_dir($path)) {
            $this->Logger->error('Directory "'. $path .'" was not created');
            return false;
        }

        $filename = sprintf('%s/%s.json', $path, strtotime($datetime));
        $packed_data = $this->DataPacker->pack($data);
        return (bool)file_put_contents($filename, $packed_data);
    }

    /**
     * @return bool
     * @throws Exception
     */
    public function createTable(): bool
    {
        $driver_name = $this->getConnection()->getDriver()->getDatabasePlatform()->getName();
        $sql_path = __DIR__ . '/../../../bin/install_data/' . $driver_name . '/source.sql';
        if (!file_exists($sql_path)) {
            $this->Logger->error('Invalid sql path:' . $sql_path);
            return false;
        }

        $sql = file_get_contents($sql_path);

        $this->getConnection()->executeStatement($sql);
        return true;
    }

    protected function getAutoLabel(): string
    {
        if (!empty($_SERVER['REQUEST_URI'])) {
            $label = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            return $label ?: $_SERVER['REQUEST_URI'];
        }

        return $_SERVER['SCRIPT_NAME'];
    }

    protected function needToStart(int $divider): bool
    {
        return mt_rand(1, $divider) === 1;
    }
}
