<?php

namespace BlockchainSdk\DTOs;

use ArrayAccess;

class TransactionReceipt implements ArrayAccess
{
    public function __construct(
        public readonly string $txHash,
        public readonly int $blockNumber,
        public readonly string $gasUsed,
        public readonly string $effectiveGasPrice,
        public readonly bool $isSuccessful,
        public readonly array $logs = [],
        public readonly array $raw = [],
        public readonly int $nativeDecimals = 18
    ) {}

    /**
     * Calculate fee in base units (e.g. wei / lamports / sun / satoshis) as string.
     */
    public function feeWei(): string
    {
        return bcmul($this->gasUsed, $this->effectiveGasPrice, 0);
    }

    /**
     * Calculate fee in human-readable native token units.
     */
    public function feeSpent(?int $decimals = null): string
    {
        $divisor = bcpow('10', (string) ($decimals ?? $this->nativeDecimals));

        return $divisor !== '0' ? bcdiv($this->feeWei(), $divisor, 12) : '0';
    }

    // --- ArrayAccess implementation for backward compatibility ---

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->raw[$offset]) || property_exists($this, (string) $offset);
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (isset($this->raw[$offset])) {
            return $this->raw[$offset];
        }

        return property_exists($this, (string) $offset) ? $this->{$offset} : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Immutable DTO
    }

    public function offsetUnset(mixed $offset): void
    {
        // Immutable DTO
    }
}
