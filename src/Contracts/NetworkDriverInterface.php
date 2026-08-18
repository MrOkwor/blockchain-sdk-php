<?php

namespace BlockchainSdk\Contracts;

use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;

interface NetworkDriverInterface
{
    public function generateWallet(): Keypair;

    public function validateAddress(string $address): bool;

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance;

    public function sendTransaction(array $params): TransactionResult;

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult;

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult;

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string;

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult;

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult;
}