<?php

namespace BlockchainSdk\Contracts;

interface SweepStateStoreInterface
{
    public function get(string $key): ?array;

    public function put(string $key, array $value): void;

    public function forget(string $key): void;

    public function synchronized(string $key, callable $work): mixed;
}
