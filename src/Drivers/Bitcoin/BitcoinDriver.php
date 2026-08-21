<?php

namespace BlockchainSdk\Drivers\Bitcoin;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Bech32;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class BitcoinDriver implements NetworkDriverInterface
{
    private BitcoinWalletGenerator $generator;
    private BitcoinTransactionSigner $signer;
    private RpcClient $rpc;
    private array $rpcNodes;

    public function __construct(array $config)
    {
        $this->generator = new BitcoinWalletGenerator();
        $this->signer = new BitcoinTransactionSigner();
        $this->rpcNodes = $config['rpc_nodes'] ?? ['https://blockstream.info/api', 'https://mempool.space/api'];
        $this->rpc = new RpcClient($this->rpcNodes);
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        // 1. Native SegWit (Bech32/Bech32m, e.g. bc1q... or bc1p...)
        if (str_starts_with($address, 'bc1') || str_starts_with($address, 'tb1')) {
            try {
                $decoded = Bech32::decode($address);
                return $decoded !== null && in_array($decoded[0], ['bc', 'tb']);
            } catch (\Throwable $e) {
                return false;
            }
        }

        // 2. Legacy / P2SH (Base58Check, e.g. 1... or 3...)
        if (preg_match('/^[13][a-km-zA-HJ-NP-Z1-9]{25,34}$/', $address)) {
            try {
                $decoded = Base58::decodeCheck($address);
                return strlen($decoded) === 21;
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
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
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;

        $utxos = $this->fetchUtxos($from);
        if (empty($utxos)) {
            return new TransactionResult(false, null, null, "No confirmed UTXOs found for address {$from}.");
        }

        $amountSat = (int)($params['amount_sat'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', 8));
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
        $client = new \GuzzleHttp\Client([
            'timeout' => 10,
            'verify'  => false,
        ]);

        $nodes = $this->rpcNodes ?? ['https://blockstream.info/api', 'https://mempool.space/api'];
        $lastError = null;

        foreach ($nodes as $baseUrl) {
            try {
                $endpoint = rtrim($baseUrl, '/') . '/tx';
                $res = $client->post($endpoint, [
                    'headers' => [
                        'Content-Type' => 'text/plain',
                        'User-Agent'   => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
                    ],
                    'body' => trim($signedRawTx),
                ]);

                $txid = trim((string)$res->getBody());
                if (!empty($txid) && !str_contains($txid, 'error')) {
                    return new TransactionResult(
                        success: true,
                        txHash: $txid,
                        rawSignedHex: $signedRawTx
                    );
                }
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
            }
        }

        return new TransactionResult(false, null, $signedRawTx, "All Bitcoin broadcast nodes failed. Last error: " . ($lastError ?? 'Unknown error'));
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        $nodes = ['https://blockstream.info/api', 'https://mempool.space/api'];
        $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false, 'http_errors' => false]);

        foreach ($nodes as $baseUrl) {
            try {
                $res = $client->get(rtrim($baseUrl, '/') . "/address/{$address}/txs");
                if ($res->getStatusCode() === 200) {
                    $txs = json_decode($res->getBody()->getContents(), true);
                    if (!empty($txs[0]['txid'])) {
                        return $txs[0]['txid'];
                    }
                }
            } catch (\Throwable $e) {
                // Try next node
            }
        }

        return null;
    }

    public function getRpc(): RpcClient
    {
        return $this->rpc;
    }
}