<?php

namespace BlockchainSdk\Drivers\Solana;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Decimal;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class SolanaDriver implements NetworkDriverInterface
{
    private SolanaWalletGenerator $generator;

    private SolanaTransactionSigner $signer;

    private RpcClient $rpc;

    public function __construct(array $config)
    {
        $this->generator = new SolanaWalletGenerator;
        $this->signer = new SolanaTransactionSigner;
        $this->rpc = new RpcClient(
            $config['rpc_nodes'] ?? ['https://api.mainnet-beta.solana.com', 'https://solana-mainnet.rpc.extrnode.com'],
            10,
            [],
            $config['verify'] ?? true
        );
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (! preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
            return false;
        }

        try {
            $bytes = Base58::decode($address);

            return strlen($bytes) === 32;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        if ($tokenContract) {
            $amountRaw = '0';
            $decimals = 6;
            try {
                $res = $this->rpc->call('getTokenAccountsByOwner', [
                    $address,
                    ['mint' => $tokenContract],
                    ['encoding' => 'jsonParsed'],
                ]);
                $accounts = $res['result']['value'] ?? [];
                if (! empty($accounts)) {
                    $info = $accounts[0]['account']['data']['parsed']['info']['tokenAmount'];
                    $amountRaw = (string) $info['amount'];
                    $decimals = (int) $info['decimals'];
                }
            } catch (\Throwable $e) {
                // Default to 0 balance if no token account found
            }

            return new TokenBalance(
                symbol: 'SPL',
                balanceRaw: $amountRaw,
                balanceFormatted: bcdiv($amountRaw, bcpow('10', (string) $decimals), min($decimals, 6)),
                decimals: $decimals
            );
        }

        $res = $this->rpc->call('getBalance', [$address]);
        $lamports = (string) ($res['result']['value'] ?? 0);

        return new TokenBalance(
            symbol: 'SOL',
            balanceRaw: $lamports,
            balanceFormatted: bcdiv($lamports, '1000000000', 6),
            decimals: 9
        );
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        if (empty($fromPrivateKey)) {
            return new TransactionResult(false, null, null, 'Private key is required for Solana transaction.');
        }

        $fromAddress = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;
        $params['from_address'] = $fromAddress;
        $params['to_address'] = $params['to'];

        // Token vs Native lamports calculation
        if (empty($params['token_contract'])) {
            $params['lamports'] = (int) ($params['lamports'] ?? Decimal::toBaseUnit($params['amount'] ?? '0', 9));
        } else {
            $decimals = (int) ($params['decimals'] ?? 6);
            $params['amount_raw'] = (string) ($params['amount_raw'] ?? Decimal::toBaseUnit($params['amount'] ?? '0', $decimals));
        }

        // Fetch and validate 32-byte blockhash from RPC
        $blockhashRes = $this->rpc->call('getLatestBlockhash', [['commitment' => 'confirmed']]);
        $recentBlockhash = $blockhashRes['result']['value']['blockhash'] ?? '';

        if (empty($recentBlockhash) || strlen(Base58::decode($recentBlockhash)) !== 32) {
            return new TransactionResult(false, null, null, 'Failed to retrieve valid 32-byte recentBlockhash from Solana RPC.');
        }

        $params['recent_blockhash'] = $recentBlockhash;

        $signedBase64 = $this->signer->signTransaction($params);

        return $this->broadcastRawTransaction($signedBase64);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $balance = $this->getBalance($from, $tokenContract);

        if ($tokenContract) {
            $sweepAmount = $amount ?? $balance->balanceRaw;

            return $this->sendTransaction([
                'from_private_key' => $fromPrivateKey,
                'to' => $toAddress,
                'token_contract' => $tokenContract,
                'amount_raw' => $sweepAmount,
                'decimals' => $balance->decimals,
            ]);
        }

        $feeLamports = 5000;
        $sweepableLamports = (int) $balance->balanceRaw - $feeLamports;

        if ($sweepableLamports <= 0) {
            return new TransactionResult(false, null, null, 'Insufficient SOL balance for rent/network fees.');
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to' => $toAddress,
            'lamports' => $sweepableLamports,
        ]);
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        return '5000'; // 5000 lamports standard signature fee
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        $requiredLamports = $this->estimateTokenTransferGasCost($tokenContract);
        $currentLamports = $this->getBalance($subWalletAddress)->balanceRaw;

        if (bccomp($currentLamports, $requiredLamports) >= 0) {
            return new TransactionResult(true, null, null, 'Sub-wallet already has sufficient SOL fee balance.');
        }

        $deficit = bcsub($requiredLamports, $currentLamports);

        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to' => $subWalletAddress,
            'lamports' => (int) $deficit + 5000,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (! $fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, 'Failed to sponsor SOL gas: '.($fuelResult->errorMessage ?? 'Unknown error'));
        }

        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->call('sendTransaction', [
                $signedRawTx,
                ['encoding' => 'base64', 'preflightCommitment' => 'confirmed'],
            ]);
            $txHash = $res['result'] ?? null;

            return new TransactionResult(
                success: ! empty($txHash),
                txHash: $txHash,
                rawSignedHex: $signedRawTx
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }

    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array
    {
        $accounts = [];
        if ($tokenContract) {
            $response = $this->rpc->call('getTokenAccountsByOwner', [$address, ['mint' => $tokenContract], ['encoding' => 'jsonParsed', 'commitment' => 'confirmed']]);
            if (! is_array($response['result']['value'] ?? null)) {
                throw new \RuntimeException('Solana token accounts are unavailable.');
            }
            foreach ($response['result']['value'] ?? [] as $account) {
                $accounts[$account['pubkey']] = $account['account']['data']['parsed']['info'];
            }
        }
        $signatures = [];
        foreach (array_unique(array_merge([$address], array_keys($accounts))) as $accountAddress) {
            $response = $this->rpc->call('getSignaturesForAddress', [$accountAddress, ['limit' => min(1000, max(1, $limit)), 'commitment' => 'confirmed']]);
            if (! is_array($response['result'] ?? null)) {
                throw new \RuntimeException('Solana transaction history is unavailable.');
            }
            foreach ($response['result'] as $signature) {
                if (! empty($signature['signature']) && ($signature['err'] ?? null) === null) {
                    $signatures[$signature['signature']] = true;
                }
            }
        }
        $height = $this->rpc->call('getSlot', [['commitment' => 'confirmed']])['result'] ?? null;
        if (! is_int($height)) {
            throw new \RuntimeException('Solana chain slot is unavailable.');
        }
        $transfers = [];
        foreach (array_keys($signatures) as $hash) {
            $transaction = $this->rpc->call('getTransaction', [$hash, ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0, 'commitment' => 'confirmed']])['result'] ?? null;
            if (! is_array($transaction) || ! is_array($transaction['meta'] ?? null)
                || ($transaction['meta']['err'] ?? null) !== null || ! isset($transaction['slot'])) {
                continue;
            }
            $message = $transaction['transaction']['message'] ?? [];
            $tokenAccounts = $accounts;
            foreach (array_merge($transaction['meta']['preTokenBalances'] ?? [], $transaction['meta']['postTokenBalances'] ?? []) as $balance) {
                $key = $message['accountKeys'][$balance['accountIndex']] ?? null;
                $key = is_array($key) ? ($key['pubkey'] ?? null) : $key;
                if ($key) {
                    $tokenAccounts[$key] = $balance;
                }
            }
            $instructions = $message['instructions'] ?? [];
            foreach ($transaction['meta']['innerInstructions'] ?? [] as $group) {
                foreach ($group['instructions'] ?? [] as $instruction) {
                    $instructions[] = $instruction;
                }
            }
            foreach ($instructions as $index => $instruction) {
                $parsed = $instruction['parsed'] ?? [];
                $info = $parsed['info'] ?? [];
                if (! in_array($parsed['type'] ?? '', ['transfer', 'transferChecked'], true)) {
                    continue;
                }
                $destination = $info['destination'] ?? '';
                $source = $info['source'] ?? '';
                if ($tokenContract) {
                    $destinationInfo = $tokenAccounts[$destination] ?? [];
                    if (! in_array($instruction['program'] ?? '', ['spl-token', 'spl-token-2022'], true)
                        || ($destinationInfo['mint'] ?? null) !== $tokenContract
                        || ($destinationInfo['owner'] ?? null) !== $address
                        || ($tokenAccounts[$source]['owner'] ?? null) === $address) {
                        continue;
                    }
                    $raw = (string) ($info['tokenAmount']['amount'] ?? $info['amount'] ?? '0');
                    $precision = $decimals;
                    $from = $tokenAccounts[$source]['owner'] ?? $info['authority'] ?? null;
                } else {
                    if (($instruction['program'] ?? '') !== 'system' || $destination !== $address || $source === $address) {
                        continue;
                    }
                    $raw = (string) ($info['lamports'] ?? '0');
                    $precision = 9;
                    $from = $source;
                }
                if (! preg_match('/^[0-9]+$/D', $raw) || bccomp($raw, '0') <= 0) {
                    continue;
                }
                $transfers[] = [
                    'tx_hash' => $hash, 'log_index' => $index, 'block_number' => (int) $transaction['slot'],
                    'from_address' => $from, 'to_address' => $address, 'amount_raw' => $raw,
                    'amount' => Decimal::fromBaseUnit($raw, $precision), 'decimals' => $precision,
                    'confirmations' => max(0, $height - (int) $transaction['slot'] + 1),
                ];
            }
        }

        return $transfers;
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        try {
            $res = $this->rpc->call('getSignaturesForAddress', [$address, ['limit' => 5]]);
            $signatures = $res['result'] ?? [];
            if (! empty($signatures[0]['signature'])) {
                return $signatures[0]['signature'];
            }
        } catch (\Throwable $e) {
            // Silently fallback
        }

        return null;
    }

    public function getTransactionReceipt(string $txHash): ?TransactionReceipt
    {
        try {
            $res = $this->rpc->call('getTransaction', [
                $txHash,
                ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0],
            ]);
            if (! empty($res['result'])) {
                $result = $res['result'];
                $meta = $result['meta'] ?? [];
                $feeLamports = (string) ($meta['fee'] ?? '5000');
                $slot = (int) ($result['slot'] ?? 0);
                $isSuccessful = empty($meta['err']);

                return new TransactionReceipt(
                    txHash: $txHash,
                    blockNumber: $slot,
                    gasUsed: $feeLamports,
                    effectiveGasPrice: '1',
                    isSuccessful: $isSuccessful,
                    logs: $meta['logMessages'] ?? [],
                    raw: $result
                );
            }
        } catch (\Throwable $e) {
            // Not yet confirmed or RPC error
        }

        return null;
    }

    public function waitForTransactionReceipt(string $txHash, int $timeoutSeconds = 60, int $pollIntervalMs = 2000): TransactionReceipt|array|null
    {
        $startTime = time();
        while ((time() - $startTime) < $timeoutSeconds) {
            $receipt = $this->getTransactionReceipt($txHash);
            if ($receipt !== null) {
                return $receipt;
            }
            usleep($pollIntervalMs * 1000);
        }

        return null;
    }

    public function getRpc(): RpcClient
    {
        return $this->rpc;
    }
}
