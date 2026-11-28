<?php

namespace BlockchainSdk\Drivers\Tron;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Decimal;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;
use GuzzleHttp\Client;

class TronDriver implements NetworkDriverInterface
{
    private TronWalletGenerator $generator;

    private TronTransactionSigner $signer;

    private RpcClient $rpc;

    public function __construct(array $config)
    {
        $this->generator = new TronWalletGenerator;
        $this->signer = new TronTransactionSigner;
        $headers = [];
        if (! empty($config['api_key'])) {
            $headers['TRON-PRO-API-KEY'] = $config['api_key'];
        }
        $this->rpc = new RpcClient(
            $config['rpc_nodes'] ?? ['https://api.trongrid.io', 'https://api.tronstack.io'],
            10,
            $headers,
            $config['verify'] ?? true
        );
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (! preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
            return false;
        }

        try {
            $decoded = Base58::decodeCheck($address);

            return strlen($decoded) === 21 && ord($decoded[0]) === 0x41;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        if ($tokenContract) {
            $addrHex = bin2hex(Base58::decodeCheck($address));
            $contractHex = bin2hex(Base58::decodeCheck($tokenContract));
            $data = '70a08231'.str_pad(substr($addrHex, 2), 64, '0', STR_PAD_LEFT);

            $res = $this->rpc->post('wallet/triggerconstantcontract', [
                'owner_address' => $addrHex,
                'contract_address' => $contractHex,
                'function_selector' => 'balanceOf(address)',
                'parameter' => substr($data, 8),
            ]);

            $constantResult = $res['constant_result'][0] ?? '0';
            $rawDec = gmp_strval(gmp_init($constantResult, 16), 10);

            return new TokenBalance(
                symbol: 'TRC20',
                balanceRaw: $rawDec,
                balanceFormatted: bcdiv($rawDec, '1000000', 6),
                decimals: 6
            );
        }

        try {
            $addrHex = str_starts_with($address, '41') && strlen($address) === 42
                ? $address
                : bin2hex(Base58::decodeCheck($address));

            $res = $this->rpc->post('wallet/getaccount', ['address' => $addrHex]);
            $sun = (string) ($res['balance'] ?? 0);

            return new TokenBalance(
                symbol: 'TRX',
                balanceRaw: $sun,
                balanceFormatted: bcdiv($sun, '1000000', 6),
                decimals: 6
            );
        } catch (\Throwable $e) {
            try {
                $client = new Client(['timeout' => 5, 'verify' => false]);
                $res = $client->get("https://api.trongrid.io/v1/accounts/{$address}");
                $data = json_decode($res->getBody()->getContents(), true);
                $sun = (string) ($data['data'][0]['balance'] ?? 0);

                return new TokenBalance(
                    symbol: 'TRX',
                    balanceRaw: $sun,
                    balanceFormatted: bcdiv($sun, '1000000', 6),
                    decimals: 6
                );
            } catch (\Throwable $ex) {
                return new TokenBalance('TRX', '0', '0.000000', 6);
            }
        }
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;

        $ownerHex = bin2hex(Base58::decodeCheck($from));
        $toHex = bin2hex(Base58::decodeCheck($params['to']));

        $tokenContract = $params['token_contract'] ?? null;

        if ($tokenContract) {
            $contractHex = bin2hex(Base58::decodeCheck($tokenContract));
            $decimals = (int) ($params['decimals'] ?? 6);
            $amountSun = (string) ($params['amount_raw'] ?? Decimal::toBaseUnit($params['amount'] ?? '0', $decimals));
            $paramHex = str_pad(substr($toHex, 2), 64, '0', STR_PAD_LEFT).str_pad(gmp_strval(gmp_init($amountSun, 10), 16), 64, '0', STR_PAD_LEFT);

            $txData = $this->rpc->post('wallet/triggersmartcontract', [
                'owner_address' => $ownerHex,
                'contract_address' => $contractHex,
                'function_selector' => 'transfer(address,uint256)',
                'parameter' => $paramHex,
                'fee_limit' => 15000000,
            ]);
            $rawTx = $txData['transaction'] ?? [];
        } else {
            $amountSun = (int) ($params['amount_sun'] ?? Decimal::toBaseUnit($params['amount'] ?? '0', 6));
            $rawTx = $this->rpc->post('wallet/createtransaction', [
                'owner_address' => $ownerHex,
                'to_address' => $toHex,
                'amount' => $amountSun,
            ]);
        }

        if (empty($rawTx['raw_data_hex'])) {
            return new TransactionResult(false, null, null, 'Failed to create TRON transaction.');
        }

        $signedJson = $this->signer->signTransaction([
            'private_key' => $fromPrivateKey,
            'raw_data_hex' => $rawTx['raw_data_hex'],
            'transaction_data' => $rawTx,
        ]);

        return $this->broadcastRawTransaction($signedJson);
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
            ]);
        }

        $feeSun = 1500000; // 1.5 TRX standard bandwidth fee
        $sweepable = bcsub($balance->balanceRaw, (string) $feeSun);
        if (bccomp($sweepable, '0') <= 0) {
            return new TransactionResult(false, null, null, 'Insufficient TRX balance to cover network fee.');
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to' => $toAddress,
            'amount_sun' => $sweepable,
        ]);
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        return '15000000'; // 15 TRX in SUN for TRC-20 energy
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        $requiredSun = $this->estimateTokenTransferGasCost($tokenContract);
        $currentSun = $this->getBalance($subWalletAddress)->balanceRaw;

        if (bccomp($currentSun, $requiredSun) >= 0) {
            return new TransactionResult(true, null, null, 'Sub-wallet already has sufficient TRX energy balance.');
        }

        $deficit = bcsub($requiredSun, $currentSun);

        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to' => $subWalletAddress,
            'amount_sun' => (int) $deficit,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (! $fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, 'Failed to sponsor TRX fee: '.($fuelResult->errorMessage ?? 'Unknown error'));
        }

        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $payload = is_array($signedRawTx) ? $signedRawTx : (json_decode($signedRawTx, true) ?? []);
            $res = $this->rpc->post('wallet/broadcasttransaction', $payload);

            return new TransactionResult($res['result'] ?? false, $res['txid'] ?? null, is_string($signedRawTx) ? $signedRawTx : json_encode($signedRawTx));
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, is_string($signedRawTx) ? $signedRawTx : json_encode($signedRawTx), $e->getMessage());
        }
    }

    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array
    {
        $path = "v1/accounts/{$address}/transactions".($tokenContract ? '/trc20' : '');
        $query = ['limit' => min(200, max(1, $limit)), 'only_to' => 'true'];
        if ($tokenContract) {
            $query['contract_address'] = $tokenContract;
        }
        $response = $this->rpc->get($path, $query);
        if (($response['success'] ?? false) !== true || ! is_array($response['data'] ?? null)) {
            throw new \RuntimeException('TRON transaction history is unavailable.');
        }
        $head = $this->rpc->post('wallet/getnowblock');
        $height = $head['block_header']['raw_data']['number'] ?? null;
        if (! is_int($height)) {
            throw new \RuntimeException('TRON chain height is unavailable.');
        }
        $transfers = [];
        $seen = [];
        foreach ($response['data'] as $candidate) {
            $hash = $candidate['transaction_id'] ?? $candidate['txID'] ?? null;
            if (! $hash || isset($seen[$hash])) {
                continue;
            }
            $seen[$hash] = true;
            $receipt = $this->rpc->post('wallet/gettransactioninfobyid', ['value' => $hash]);
            if (($receipt['id'] ?? null) !== $hash || ! isset($receipt['blockNumber'])) {
                continue;
            }
            $result = $receipt['receipt']['result'] ?? ($tokenContract ? null : 'SUCCESS');
            if ($result !== 'SUCCESS' || isset($receipt['result'])) {
                continue;
            }
            $block = (int) $receipt['blockNumber'];
            $confirmations = max(0, $height - $block + 1);
            if ($tokenContract) {
                foreach ($receipt['log'] ?? [] as $index => $log) {
                    $topics = $log['topics'] ?? [];
                    if ($this->depositAddress($log['address'] ?? '') !== $tokenContract
                        || count($topics) !== 3
                        || strtolower($topics[0] ?? '') !== 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef'
                        || ! preg_match('/^[0-9a-f]{64}$/iD', $topics[1] ?? '')
                        || ! preg_match('/^[0-9a-f]{64}$/iD', $topics[2] ?? '')
                        || $this->depositAddress(substr($topics[2], -40)) !== $address
                        || ! preg_match('/^[0-9a-f]{64}$/iD', $log['data'] ?? '')) {
                        continue;
                    }
                    $raw = gmp_strval(gmp_init($log['data'], 16));
                    if (bccomp($raw, '0') <= 0) {
                        continue;
                    }
                    $transfers[] = [
                        'tx_hash' => $hash, 'log_index' => $index, 'block_number' => $block,
                        'from_address' => $this->depositAddress(substr($topics[1], -40)), 'to_address' => $address,
                        'amount_raw' => $raw, 'amount' => Decimal::fromBaseUnit($raw, $decimals),
                        'decimals' => $decimals, 'confirmations' => $confirmations,
                    ];
                }
            } else {
                foreach ($candidate['raw_data']['contract'] ?? [] as $index => $contract) {
                    $info = $contract['parameter']['value'] ?? [];
                    $raw = (string) ($info['amount'] ?? '0');
                    if (($contract['type'] ?? null) !== 'TransferContract'
                        || $this->depositAddress($info['to_address'] ?? '') !== $address || bccomp($raw, '0') <= 0) {
                        continue;
                    }
                    $transfers[] = [
                        'tx_hash' => $hash, 'log_index' => $index, 'block_number' => $block,
                        'from_address' => $this->depositAddress($info['owner_address'] ?? ''), 'to_address' => $address,
                        'amount_raw' => $raw, 'amount' => Decimal::fromBaseUnit($raw, 6),
                        'decimals' => 6, 'confirmations' => $confirmations,
                    ];
                }
            }
        }

        return $transfers;
    }

    private function depositAddress(string $address): string
    {
        if (preg_match('/^(41)?[0-9a-f]{40}$/iD', $address)) {
            return Base58::encodeCheck(hex2bin('41'.substr($address, -40)));
        }

        return $address;
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        try {
            $transfers = $this->getIncomingTransactions($address, $tokenContract, limit: 5);

            return $transfers[0]['tx_hash'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function getTransactionReceipt(string $txHash): ?TransactionReceipt
    {
        try {
            $res = $this->rpc->post('wallet/gettransactioninfobyid', ['value' => $txHash]);
            if (! empty($res['id'])) {
                $feeSun = (string) ($res['fee'] ?? '0');
                $blockNumber = (int) ($res['blockNumber'] ?? 0);
                $contractResult = $res['receipt']['result'] ?? ($res['contractResult'][0] ?? 'SUCCESS');
                $isSuccessful = ($contractResult === 'SUCCESS');

                return new TransactionReceipt(
                    txHash: $txHash,
                    blockNumber: $blockNumber,
                    gasUsed: $feeSun,
                    effectiveGasPrice: '1',
                    isSuccessful: $isSuccessful,
                    logs: $res['log'] ?? [],
                    raw: $res
                );
            }
        } catch (\Throwable $e) {
            // Pending or node error
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
