<?php

namespace BlockchainSdk\DTOs;

class TransactionResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $txHash = null,
        public readonly ?string $rawSignedHex = null,
        public readonly ?string $errorMessage = null,
        public readonly array $meta = []
    ) {}
}