<?php

namespace BlockchainSdk\Contracts;

use BlockchainSdk\DTOs\Keypair;

interface WalletGeneratorInterface
{
    public function generateWallet(): Keypair;
    public function privateKeyToAddress(string $privateKey): string;
}