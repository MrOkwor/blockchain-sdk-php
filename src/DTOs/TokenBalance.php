<?php

namespace BlockchainSdk\DTOs;

class TokenBalance
{
    public function __construct(
        public readonly string $symbol,
        public readonly string $balanceRaw,
        public readonly string $balanceFormatted,
        public readonly int $decimals
    ) {}
}