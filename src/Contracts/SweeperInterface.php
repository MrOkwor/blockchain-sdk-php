<?php

namespace BlockchainSdk\Contracts;

use BlockchainSdk\DTOs\TransactionResult;

interface SweeperInterface
{
    public function sweep(
        string $fromPrivateKey,
        string $toAddress,
        ?string $tokenContract = null,
        ?string $amount = null
    ): TransactionResult;
}