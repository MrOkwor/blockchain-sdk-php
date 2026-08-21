<?php

namespace BlockchainSdk\Drivers\Tron;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class TronDriver implements NetworkDriverInterface
{
    private TronWalletGenerator $generator;
    private TronTransactionSigner $signer;
    private RpcClient $rpc;

    public function __construct(array $config)
    {
        $this->generator = new TronWalletGenerator();
        $this->signer = new TronTransactionSigner();
        $headers = [];
        if (!empty($config['api_key'])) {
            $headers['TRON-PRO-API-KEY'] = $config['api_key'];
        }
        $this->rpc = new RpcClient($config['rpc_nodes'] ?? ['https://api.trongrid.io', 'https://api.tronstack.io'], 10, $headers);
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (!preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $address)) {
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
            $data = '70a08231' . str_pad(substr($addrHex, 2), 64, '0', STR_PAD_LEFT);

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
            $sun = (string)($res['balance'] ?? 0);

            return new TokenBalance(
                symbol: 'TRX',
                balanceRaw: $sun,
                balanceFormatted: bcdiv($sun, '1000000', 6),
                decimals: 6
            );
        } catch (\Throwable $e) {
            try {
                $client = new \GuzzleHttp\Client(['timeout' => 5, 'verify' => false]);
                $res = $client->get("https://api.trongrid.io/v1/accounts/{$address}");
                $data = json_decode($res->getBody()->getContents(), true);
                $sun = (string)($data['data'][0]['balance'] ?? 0);

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
            $decimals = (int)($params['decimals'] ?? 6);
            $amountSun = (string)($params['amount_raw'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', $decimals));
            $paramHex = str_pad(substr($toHex, 2), 64, '0', STR_PAD_LEFT) . str_pad(gmp_strval(gmp_init($amountSun, 10), 16), 64, '0', STR_PAD_LEFT);

            $txData = $this->rpc->post('wallet/triggersmartcontract', [
                'owner_address' => $ownerHex,
                'contract_address' => $contractHex,
                'function_selector' => 'transfer(address,uint256)',
                'parameter' => $paramHex,
                'fee_limit' => 15000000,
            ]);
            $rawTx = $txData['transaction'] ?? [];
        } else {
            $amountSun = (int)($params['amount_sun'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', 6));
            $rawTx = $this->rpc->post('wallet/createtransaction', [
                'owner_address' => $ownerHex,
                'to_address' => $toHex,
                'amount' => $amountSun,
            ]);
        }

        if (empty($rawTx['raw_data_hex'])) {
            return new TransactionResult(false, null, null, "Failed to create TRON transaction.");
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
        $sweepable = bcsub($balance->balanceRaw, (string)$feeSun);
        if (bccomp($sweepable, '0') <= 0) {
            return new TransactionResult(false, null, null, "Insufficient TRX balance to cover network fee.");
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
            return new TransactionResult(true, null, null, "Sub-wallet already has sufficient TRX energy balance.");
        }

        $deficit = bcsub($requiredSun, $currentSun);
        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to'               => $subWalletAddress,
            'amount_sun'       => (int)$deficit,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (!$fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, "Failed to sponsor TRX fee: " . ($fuelResult->errorMessage ?? 'Unknown error'));
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

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        try {
            $client = new \GuzzleHttp\Client(['timeout' => 5, 'http_errors' => false]);
            if ($tokenContract) {
                $url = "https://api.trongrid.io/v1/accounts/{$address}/transactions/trc20?limit=5";
                $res = $client->get($url);
                if ($res->getStatusCode() === 200) {
                    $data = json_decode($res->getBody()->getContents(), true);
                    foreach ($data['data'] ?? [] as $tx) {
                        $to = $tx['to'] ?? '';
                        if ($to === $address) {
                            return $tx['transaction_id'] ?? null;
                        }
                    }
                }
            } else {
                $url = "https://api.trongrid.io/v1/accounts/{$address}/transactions?limit=5";
                $res = $client->get($url);
                if ($res->getStatusCode() === 200) {
                    $data = json_decode($res->getBody()->getContents(), true);
                    foreach ($data['data'] ?? [] as $tx) {
                        $param = $tx['raw_data']['contract'][0]['parameter']['value'] ?? [];
                        $to = $param['to_address'] ?? '';
                        if (!empty($tx['txID'])) {
                            return $tx['txID'];
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fallback
        }

        return null;
    }

    public function getRpc(): RpcClient
    {
        return $this->rpc;
    }
}