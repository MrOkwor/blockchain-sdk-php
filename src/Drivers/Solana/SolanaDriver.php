<?php

namespace BlockchainSdk\Drivers\Solana;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class SolanaDriver implements NetworkDriverInterface
{
    private SolanaWalletGenerator $generator;
    private SolanaTransactionSigner $signer;
    private RpcClient $rpc;

    public function __construct(array $config)
    {
        $this->generator = new SolanaWalletGenerator();
        $this->signer = new SolanaTransactionSigner();
        $this->rpc = new RpcClient($config['rpc_nodes'] ?? ['https://api.mainnet-beta.solana.com', 'https://solana-mainnet.rpc.extrnode.com']);
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (!preg_match('/^[1-9A-HJ-NP-Za-km-z]{32,44}$/', $address)) {
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
            $res = $this->rpc->call('getTokenAccountsByOwner', [
                $address,
                ['mint' => $tokenContract],
                ['encoding' => 'jsonParsed']
            ]);
            $accounts = $res['result']['value'] ?? [];
            $amountRaw = '0';
            $decimals = 6;
            if (!empty($accounts)) {
                $info = $accounts[0]['account']['data']['parsed']['info']['tokenAmount'];
                $amountRaw = (string)$info['amount'];
                $decimals = (int)$info['decimals'];
            }
            return new TokenBalance(
                symbol: 'SPL',
                balanceRaw: $amountRaw,
                balanceFormatted: bcdiv($amountRaw, bcpow('10', (string)$decimals), 6),
                decimals: $decimals
            );
        }

        $res = $this->rpc->call('getBalance', [$address]);
        $lamports = (string)($res['result']['value'] ?? 0);

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
            return new TransactionResult(false, null, null, "Private key is required for Solana transaction.");
        }

        $fromAddress = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key']      = $fromPrivateKey;
        $params['from_address']     = $fromAddress;
        $params['to_address']       = $params['to'];

        // Token vs Native lamports calculation
        if (empty($params['token_contract'])) {
            $params['lamports'] = (int)($params['lamports'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', 9));
        } else {
            $decimals = (int)($params['decimals'] ?? 6);
            $params['amount_raw'] = (string)($params['amount_raw'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', $decimals));
        }

        // Fetch and validate 32-byte blockhash from RPC
        $blockhashRes = $this->rpc->call('getLatestBlockhash', [['commitment' => 'confirmed']]);
        $recentBlockhash = $blockhashRes['result']['value']['blockhash'] ?? '';

        if (empty($recentBlockhash) || strlen(Base58::decode($recentBlockhash)) !== 32) {
            return new TransactionResult(false, null, null, "Failed to retrieve valid 32-byte recentBlockhash from Solana RPC.");
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
        $sweepableLamports = (int)$balance->balanceRaw - $feeLamports;

        if ($sweepableLamports <= 0) {
            return new TransactionResult(false, null, null, "Insufficient SOL balance for rent/network fees.");
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
            return new TransactionResult(true, null, null, "Sub-wallet already has sufficient SOL fee balance.");
        }

        $deficit = bcsub($requiredLamports, $currentLamports);
        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to'               => $subWalletAddress,
            'lamports'         => (int)$deficit + 5000,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (!$fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, "Failed to sponsor SOL gas: " . ($fuelResult->errorMessage ?? 'Unknown error'));
        }

        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->call('sendTransaction', [
                $signedRawTx,
                ['encoding' => 'base64', 'preflightCommitment' => 'confirmed']
            ]);
            $txHash = $res['result'] ?? null;
            return new TransactionResult(
                success: !empty($txHash),
                txHash: $txHash,
                rawSignedHex: $signedRawTx
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        try {
            $res = $this->rpc->call('getSignaturesForAddress', [$address, ['limit' => 5]]);
            $signatures = $res['result'] ?? [];
            if (!empty($signatures[0]['signature'])) {
                return $signatures[0]['signature'];
            }
        } catch (\Throwable $e) {
            // Silently fallback
        }

        return null;
    }
}