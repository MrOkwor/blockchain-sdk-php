<?php

namespace BlockchainSdk\Drivers\Evm;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;

class EvmDriver implements NetworkDriverInterface
{
    private EvmWalletGenerator $generator;
    private EvmTransactionSigner $signer;
    private RpcClient $rpc;
    private int $chainId;
    private string $currency;

    public function __construct(array $config)
    {
        $this->generator = new EvmWalletGenerator();
        $this->signer = new EvmTransactionSigner();
        $this->rpc = new RpcClient($config['rpc_nodes'] ?? ['https://cloudflare-eth.com']);
        $this->chainId = (int)($config['chain_id'] ?? 1);
        $this->currency = $config['currency'] ?? 'ETH';
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            return false;
        }

        // If purely lowercase or purely uppercase, valid format
        $sub = substr($address, 2);
        if (strtolower($sub) === $sub || strtoupper($sub) === $sub) {
            return true;
        }

        // If mixed-case, verify EIP-55 checksum
        return EvmWalletGenerator::toChecksumAddress($address) === $address;
    }

    public function getBalance(string $address, ?string $tokenContract = null): TokenBalance
    {
        if ($tokenContract) {
            $data = '0x70a08231000000000000000000000000' . ltrim($address, '0x');
            $res = $this->rpc->call('eth_call', [['to' => $tokenContract, 'data' => $data], 'latest']);
            $hex = $res['result'] ?? '0x0';
            $rawDec = gmp_strval(gmp_init($hex, 16), 10);

            // Fetch token decimals dynamically (0x313ce567 = decimals())
            $decRes = $this->rpc->call('eth_call', [['to' => $tokenContract, 'data' => '0x313ce567'], 'latest']);
            $decimals = hexdec($decRes['result'] ?? '0x12');
            if ($decimals <= 0 || $decimals > 36) {
                $decimals = 18;
            }

            $formatted = bcdiv($rawDec, bcpow('10', (string)$decimals), min($decimals, 8));
            return new TokenBalance('TOKEN', $rawDec, $formatted, $decimals);
        }

        $res = $this->rpc->call('eth_getBalance', [$address, 'latest']);
        $hex = $res['result'] ?? '0x0';
        $wei = gmp_strval(gmp_init($hex, 16), 10);
        return new TokenBalance($this->currency, $wei, bcdiv($wei, '1000000000000000000', 6), 18);
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;
        
        $nonceRes = $this->rpc->call('eth_getTransactionCount', [$from, 'pending']);
        $nonce = hexdec($nonceRes['result'] ?? '0x0');

        $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
        $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);

        $params['nonce'] = $params['nonce'] ?? $nonce;
        $params['gas_price'] = $params['gas_price'] ?? $gasPriceWei;

        $tokenContract = $params['token_contract'] ?? null;
        if ($tokenContract && empty($params['data'])) {
            $recipient = $params['to'];
            $decimals = (int)($params['decimals'] ?? 18);
            $amountRaw = (string)($params['amount_raw'] ?? \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'] ?? '0', $decimals));
            $params['to'] = $tokenContract;
            $params['data'] = EvmTransactionSigner::buildErc20TransferData($recipient, $amountRaw);
            $params['value'] = '0';
        } elseif (isset($params['amount']) && !isset($params['value'])) {
            $params['value'] = \BlockchainSdk\Crypto\Decimal::toBaseUnit($params['amount'], 18);
        }

        // Dynamically estimate gas limit with eth_estimateGas to support smart contracts, EIP-7702, and EOAs
        if (!isset($params['gas_limit'])) {
            try {
                $estimateParams = [
                    'from'  => $from,
                    'to'    => $params['to'],
                    'value' => '0x' . gmp_strval(gmp_init($params['value'] ?? '0', 10), 16),
                ];
                if (!empty($params['data'])) {
                    $estimateParams['data'] = $params['data'];
                }
                $estRes = $this->rpc->call('eth_estimateGas', [$estimateParams]);
                $estimatedGas = hexdec($estRes['result'] ?? '0x0');
                if ($estimatedGas > 0) {
                    $params['gas_limit'] = max((int)($estimatedGas * 1.25), 21000);
                }
            } catch (\Throwable $e) {
                // Fallback safe default
            }

            if (!isset($params['gas_limit'])) {
                $params['gas_limit'] = !empty($params['data']) ? 65000 : 35000;
            }
        }

        $params['chain_id'] = $this->chainId;

        $signedRaw = $this->signer->signTransaction($params);
        return $this->broadcastRawTransaction($signedRaw);
    }

    public function sweep(string $fromPrivateKey, string $toAddress, ?string $tokenContract = null, ?string $amount = null): TransactionResult
    {
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $balance = $this->getBalance($from, $tokenContract);

        if ($tokenContract) {
            $sweepAmount = $amount ?? $balance->balanceRaw;
            if (bccomp($sweepAmount, '0') <= 0) {
                return new TransactionResult(false, null, null, "No token balance found to sweep on {$from}.");
            }

            $data = EvmTransactionSigner::buildErc20TransferData($toAddress, $sweepAmount);
            return $this->sendTransaction([
                'from_private_key' => $fromPrivateKey,
                'to' => $tokenContract,
                'value' => '0',
                'data' => $data,
                'gas_limit' => 65000,
            ]);
        }

        $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
        $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);
        $totalGasFee = bcmul($gasPriceWei, '21000');
        // Add 10% safety buffer for gas price fluctuations
        $gasFeeWithBuffer = bcadd($totalGasFee, bcdiv($totalGasFee, '10', 0));

        $sweepable = bcsub($balance->balanceRaw, $gasFeeWithBuffer);
        if (bccomp($sweepable, '0') <= 0) {
            return new TransactionResult(false, null, null, "Insufficient native balance to cover transaction gas fee.");
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to'               => $toAddress,
            'value'            => $sweepable,
            'gas_limit'        => 21000,
            'gas_price'        => $gasPriceWei,
        ]);
    }

    public function getTransactionReceipt(string $txHash): ?array
    {
        try {
            $res = $this->rpc->call('eth_getTransactionReceipt', [$txHash]);
            return $res['result'] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function estimateTokenTransferGasCost(?string $tokenContract = null): string
    {
        try {
            $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
            $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);
            return bcmul($gasPriceWei, '65000'); // Standard ERC-20 transfer limit
        } catch (\Throwable $e) {
            return '1500000000000000'; // Default ~0.0015 ETH/BNB
        }
    }

    public function fuelSubWallet(string $masterGasPrivateKey, string $subWalletAddress, ?string $tokenContract = null): TransactionResult
    {
        $requiredGasWei = $this->estimateTokenTransferGasCost($tokenContract);
        $currentBalanceWei = $this->getBalance($subWalletAddress)->balanceRaw;

        // If sub-wallet already has enough gas, no fueling needed
        if (bccomp($currentBalanceWei, $requiredGasWei) >= 0) {
            return new TransactionResult(true, null, null, "Sub-wallet already has sufficient gas.");
        }

        $deficitWei = bcsub($requiredGasWei, $currentBalanceWei);
        // Add 10% safety buffer for gas price fluctuations
        $fuelAmountWei = bcadd($deficitWei, bcdiv($deficitWei, '10', 0));

        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to'               => $subWalletAddress,
            'value'            => $fuelAmountWei,
            'gas_limit'        => 21000,
        ]);
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);
        
        // 1. Ensure sub-wallet is fueled with native gas
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (!$fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, "Failed to fuel sub-wallet with gas: " . ($fuelResult->errorMessage ?? 'Unknown error'));
        }

        // 2. Execute ERC-20 token sweep
        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->call('eth_sendRawTransaction', [$signedRawTx]);
            $txHash = $res['result'] ?? null;
            return new TransactionResult(
                success: !empty($txHash),
                txHash: $txHash,
                rawSignedHex: $signedRawTx,
                errorMessage: $res['error']['message'] ?? null
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        try {
            $currentBlockHex = $this->rpc->call('eth_blockNumber', [])['result'] ?? null;
            if (!$currentBlockHex) return null;
            $currentBlock = hexdec($currentBlockHex);

            // 1. If ERC-20 token, search Transfer logs backwards in 50-block chunks (up to 1,000 blocks back)
            if ($tokenContract) {
                $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
                $paddedAddress = '0x' . str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
                $chunkSize = 50;
                $maxLookback = 1000;

                for ($i = 0; $i * $chunkSize < $maxLookback; $i++) {
                    $toBlock = $currentBlock - ($i * $chunkSize);
                    $fromBlock = max(0, $toBlock - $chunkSize + 1);

                    $res = $this->rpc->call('eth_getLogs', [[
                        'fromBlock' => '0x' . dechex($fromBlock),
                        'toBlock'   => '0x' . dechex($toBlock),
                        'address'   => $tokenContract,
                        'topics'    => [$transferTopic, null, $paddedAddress],
                    ]]);

                    $logs = $res['result'] ?? [];
                    if (!empty($logs)) {
                        $lastLog = end($logs);
                        if (!empty($lastLog['transactionHash'])) {
                            return $lastLog['transactionHash'];
                        }
                    }
                }
            }

            // 2. Blockscout v2 Keyless API fallback for supported EVM chains
            $blockscoutHosts = [
                1      => 'eth.blockscout.com',
                10     => 'optimism.blockscout.com',
                56     => 'bsc.blockscout.com',
                137    => 'polygon.blockscout.com',
                8453   => 'base.blockscout.com',
                42161  => 'arbitrum.blockscout.com',
                42220  => 'celo.blockscout.com',
                534352 => 'scroll.blockscout.com',
            ];

            if (isset($blockscoutHosts[$this->chainId])) {
                $host = $blockscoutHosts[$this->chainId];
                $client = new \GuzzleHttp\Client(['timeout' => 5, 'http_errors' => false]);
                $url = "https://{$host}/api/v2/addresses/{$address}/token-transfers";
                $res = $client->get($url);
                if ($res->getStatusCode() === 200) {
                    $data = json_decode($res->getBody()->getContents(), true);
                    foreach ($data['items'] ?? [] as $item) {
                        $to = strtolower($item['to']['hash'] ?? '');
                        if ($to === strtolower($address)) {
                            if (!$tokenContract || strtolower($item['token']['address'] ?? '') === strtolower($tokenContract)) {
                                return $item['transaction_hash'] ?? null;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently fallback
        }

        return null;
    }
}