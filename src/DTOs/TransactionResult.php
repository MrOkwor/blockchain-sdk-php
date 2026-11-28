<?php

namespace BlockchainSdk\DTOs;

class TransactionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $txHash = null,
        public readonly ?string $rawSignedHex = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = [],
        public readonly ?TransactionReceipt $receipt = null
    ) {}

    /**
     * Gas fee spent in native token (human-readable).
     * Computed from receipt if available.
     */
    public function feeSpent(?int $decimals = null): ?string
    {
        return $this->receipt?->feeSpent($decimals);
    }
}
