<?php

namespace BlockchainSdk\Laravel;

use BlockchainSdk\Contracts\SweepStateStoreInterface;
use Illuminate\Contracts\Cache\Repository;

class CacheSweepStateStore implements SweepStateStoreInterface
{
    public function __construct(private Repository $cache) {}

    public function get(string $key): ?array
    {
        return $this->cache->get('blockchainsdk:pending:'.$key);
    }

    public function put(string $key, array $value): void
    {
        $this->cache->forever('blockchainsdk:pending:'.$key, $value);
    }

    public function forget(string $key): void
    {
        $this->cache->forget('blockchainsdk:pending:'.$key);
    }

    public function synchronized(string $key, callable $work): mixed
    {
        return $this->cache->getStore()->lock('blockchainsdk:lock:'.$key, 180)->block(2, $work);
    }
}
