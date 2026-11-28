<?php

namespace BlockchainSdk\Services;

use BlockchainSdk\Contracts\SweepStateStoreInterface;
use RuntimeException;

/** Process-local fallback. Standalone workers must inject durable, shared storage for production. */
class MemorySweepStateStore implements SweepStateStoreInterface
{
    private array $values = [];

    private array $locks = [];

    public function get(string $key): ?array
    {
        return $this->values[$key] ?? null;
    }

    public function put(string $key, array $value): void
    {
        $this->values[$key] = $value;
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    public function synchronized(string $key, callable $work): mixed
    {
        if (isset($this->locks[$key])) {
            throw new RuntimeException('A wallet operation is already in progress.');
        }
        $this->locks[$key] = true;
        try {
            return $work();
        } finally {
            unset($this->locks[$key]);
        }
    }
}
