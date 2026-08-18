<?php

namespace BlockchainSdk\Drivers\Bitcoin;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class BitcoinDriver implements NetworkDriverInterface
{
    private BitcoinWalletGenerator $generator;
    private BitcoinTransactionSigner $signer;
    private RpcClient $rpc;

    public function __construct(array $config)
    {
        $this->generator = new BitcoinWalletGenerator();
        $this->signer = new BitcoinTransactionSigner();
        $this->rpc = new RpcClient($config['rpc_nodes'] ?? ['https://mempool.space/api', 'https://blockstream.info/api']);
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        try {
            $data = $this->rpc->get("address/{$address}");
            $funded = $data['chain_stats']['funded_txo_sum'] ?? 0;
            $spent = $data['chain_stats']['spent_txo_sum'] ?? 0;
            $satoshis = (string)($funded - $spent);

            return new TokenBalance(
                symbol: 'BTC',
                balanceRaw: $satoshis,
                balanceFormatted: bcdiv($satoshis, '100000000', 8),
                decimals: 8
            );
        } catch (\Throwable $e) {
            return new TokenBalance('BTC', '0', '0.00000000', 8);
        }
    }

    public function fetchUtxos(string $address): array
    {
        try {
            return $this->rpc->get("address/{$address}/utxo") ?? [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $from = $this->generator->privateKeyToAddress($params['from_private_key']);
        $utxos = $this->fetchUtxos($from);
        if (empty($utxos)) {
            return new TransactionResult(false, null, null, "No confirmed UTXOs found for address {$from}.");
        }

        $amountSat = (int)($params['amount_sat'] ?? (floatval($params['amount'] ?? 0) * 1e8));
        $feeRate = (int)($params['fee_rate'] ?? 20);

        $unsignedTx = BitcoinTransactionSigner::buildUnsignedSegwitTx(
            utxos: $utxos,
            toAddress: $params['to'],
            amountSat: $amountSat,
            changeAddress: $from,
            feeRateSatVb: $feeRate
        );

        if (!$unsignedTx) {
            return new TransactionResult(false, null, null, "Failed to assemble transaction. Insufficient funds or dust output.");
        }

        $signedRawHex = $this->signer->signTransaction([
            'tx' => $unsignedTx,
            'private_key' => $params['from_private_key'],
        ]);

        return $this->broadcastRawTransaction($signedRawHex);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $utxos = $this->fetchUtxos($from);
        if (empty($utxos)) {
            return new TransactionResult(false, null, null, "No UTXOs available to sweep for {$from}.");
        }

        $totalInputSat = array_sum(array_column($utxos, 'value'));
        $estimatedVSize = (count($utxos) * 68) + (1 * 31) + 11;
        $feeSat = $estimatedVSize * 20;

        $sweepableSat = $totalInputSat - $feeSat;
        if ($sweepableSat <= 546) {
            return new TransactionResult(false, null, null, "UTXO balance too low to cover mining fees.");
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to' => $toAddress,
            'amount_sat' => $sweepableSat,
            'fee_rate' => 20,
        ]);
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        return '2500'; // ~2500 sats standard UTXO fee
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        return new TransactionResult(true, null, null, "Bitcoin UTXO inputs cover fees directly.");
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->post('tx', ['tx' => $signedRawTx]);
            $txid = is_string($res) ? trim($res) : ($res['txid'] ?? null);
            return new TransactionResult(
                success: !empty($txid),
                txHash: $txid,
                rawSignedHex: $signedRawTx
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }
}