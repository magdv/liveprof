<?php

/**
 * Class to prepare profiler data to dave in db
 * @maintainer Timur Shagiakhmetov <timur.shagiakhmetov@corp.badoo.com>
 */

namespace Badoo\LiveProfiler;

class DataPacker implements DataPackerInterface
{
    public function pack(array $data): string
    {
        return json_encode($data);
    }

    public function unpack(string $data): array
    {
        return json_decode($data, true);
    }
}
