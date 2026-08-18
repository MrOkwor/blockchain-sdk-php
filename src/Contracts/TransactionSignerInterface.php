<?php

namespace BlockchainSdk\Contracts;

use BlockchainSdk\DTOs\TransactionResult;

interface TransactionSignerInterface
{
    public function signTransaction(array $params): string;
}