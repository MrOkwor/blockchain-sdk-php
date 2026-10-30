<?php

namespace BlockchainSdk\Contracts;

use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;

interface NetworkDriverInterface
{
    public function generateWallet(): Keypair;

    public function validateAddress(string $address): bool;

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance;

    public function sendTransaction(array $params): TransactionResult;

    /**
     * Sweep native currency or token balance into a target address.
     *
     * @param  string  $fromPrivateKey  Private key of the source wallet
     * @param  string  $toAddress  Destination address (e.g. Master Vault)
     * @param  string|null  $tokenContract  Token contract address (optional for native currency)
     * @param  string|null  $amount  Atomic base-unit string (wei, lamports, sun, satoshis).
     *                               See Decimal::toBaseUnit() to convert human-readable amounts.
     */
    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult;

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult;

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string;

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult;

    /**
     * Sweep token with automated native gas sponsorship.
     *
     * @param  string  $subWalletPrivateKey  Private key of the sub-wallet
     * @param  string  $masterGasPrivateKey  Private key of the gas funding wallet
     * @param  string  $toVaultAddress  Destination vault address
     * @param  string  $tokenContract  Token contract address
     * @param  string|null  $amount  Atomic base-unit string (wei, lamports, sun).
     *                               See Decimal::toBaseUnit() to convert human-readable amounts.
     */
    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult;

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string;

    /**
     * @return array<int, array{tx_hash: string, log_index: int, block_number: int|null, from_address: string|null, to_address: string, amount_raw: string, amount: string, decimals: int, confirmations: int}>
     */
    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array;

    /**
     * Retrieve transaction receipt by hash if already mined.
     */
    public function getTransactionReceipt(string $txHash): ?TransactionReceipt;

    /**
     * Poll for on-chain transaction receipt confirmation until resolved or timeout.
     */
    public function waitForTransactionReceipt(string $txHash, int $timeoutSeconds = 60, int $pollIntervalMs = 2000): TransactionReceipt|array|null;
}
