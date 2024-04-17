<?php

/**
 * @maintainer Timur Shagiakhmetov <timur.shagiakhmetov@corp.badoo.com>
 */

namespace Badoo\LiveProfiler;

interface DataPackerInterface
{
    public function pack(array $data): string;

    public function unpack(string $data): array;
}
