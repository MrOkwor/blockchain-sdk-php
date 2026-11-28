<?php

namespace BlockchainSdk\Drivers\Evm;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Crypto\Decimal;
use BlockchainSdk\DTOs\Keypair;
use BlockchainSdk\DTOs\TokenBalance;
use BlockchainSdk\DTOs\TransactionReceipt;
use BlockchainSdk\DTOs\TransactionResult;
use BlockchainSdk\Http\RpcClient;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;

class EvmDriver implements NetworkDriverInterface
{
    private EvmWalletGenerator $generator;

    private EvmTransactionSigner $signer;

    private RpcClient $rpc;

    private int $chainId;

    private string $currency;

    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->generator = new EvmWalletGenerator;
        $this->signer = new EvmTransactionSigner;
        $this->rpc = new RpcClient(
            $config['rpc_nodes'] ?? ['https://cloudflare-eth.com'],
            10,
            [],
            $config['verify'] ?? true
        );
        $this->chainId = (int) ($config['chain_id'] ?? 1);
        $this->currency = $config['currency'] ?? 'ETH';
    }

    public function generateWallet(): Keypair
    {
        return $this->generator->generateWallet();
    }

    public function validateAddress(string $address): bool
    {
        if (! preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
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
            $cleanAddr = strtolower(EvmTransactionSigner::strip0x($address));
            $data = '0x70a08231'.str_pad($cleanAddr, 64, '0', STR_PAD_LEFT);
            try {
                $res = $this->rpc->call('eth_call', [['to' => $tokenContract, 'data' => $data], 'latest']);
                $hex = $res['result'] ?? '0x0';
            } catch (\Throwable $e) {
                $hex = '0x0';
            }
            if (empty($hex) || $hex === '0x' || ! ctype_xdigit(EvmTransactionSigner::strip0x($hex))) {
                $hex = '0x0';
            }
            $rawDec = gmp_strval(gmp_init($hex, 16), 10);

            $decimals = $this->resolveTokenDecimals($tokenContract);

            $formatted = bcpow('10', (string) $decimals) !== '0'
                ? bcdiv($rawDec, bcpow('10', (string) $decimals), min($decimals, 8))
                : '0';

            return new TokenBalance('TOKEN', $rawDec, $formatted, $decimals);
        }

        $res = $this->rpc->call('eth_getBalance', [$address, 'latest']);
        $hex = $res['result'] ?? '0x0';
        $wei = gmp_strval(gmp_init($hex, 16), 10);

        return new TokenBalance($this->currency, $wei, bcdiv($wei, '1000000000000000000', 6), 18);
    }

    private static array $allocatedNonces = [];

    public function getNextNonce(string $address): int
    {
        $addressKey = strtolower(EvmTransactionSigner::strip0x($address));
        $cacheKey = "blockchainsdk_nonce_{$this->chainId}_{$addressKey}";

        // 1. Laravel Multi-Worker / Multi-Server Environment: Atomic Cache Lock
        if (class_exists(Cache::class)) {
            try {
                return Cache::lock("lock_{$cacheKey}", 5)->block(5, function () use ($address, $cacheKey) {
                    $res = $this->rpc->call('eth_getTransactionCount', [$address, 'pending']);
                    $onChainNonce = hexdec($res['result'] ?? '0x0');
                    $cachedNonce = (int) Cache::get($cacheKey, -1);
                    $nextNonce = max($onChainNonce, $cachedNonce + 1);

                    Cache::put($cacheKey, $nextNonce, 300);

                    return $nextNonce;
                });
            } catch (\Throwable $e) {
                // Fallback to native OS lock
            }
        }

        // 2. Plain PHP Multi-Process Environment: Native OS File Lock (flock)
        $lockFilePath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'blockchainsdk_nonce_'.md5($cacheKey).'.lock';
        $fp = @fopen($lockFilePath, 'c+');
        if ($fp && @flock($fp, LOCK_EX)) {
            try {
                $res = $this->rpc->call('eth_getTransactionCount', [$address, 'pending']);
                $onChainNonce = hexdec($res['result'] ?? '0x0');

                $storedNonce = -1;
                rewind($fp);
                $content = stream_get_contents($fp);
                if ($content !== false && $content !== '') {
                    $storedNonce = (int) trim($content);
                }

                $nextNonce = max($onChainNonce, $storedNonce + 1);

                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, (string) $nextNonce);
                fflush($fp);
                flock($fp, LOCK_UN);
                fclose($fp);

                return $nextNonce;
            } catch (\Throwable $e) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
        }

        // 3. Fallback: Process-local static memory
        $res = $this->rpc->call('eth_getTransactionCount', [$address, 'pending']);
        $onChainNonce = hexdec($res['result'] ?? '0x0');
        $localNonce = self::$allocatedNonces[$addressKey] ?? -1;
        $nextNonce = max($onChainNonce, $localNonce + 1);
        self::$allocatedNonces[$addressKey] = $nextNonce;

        return $nextNonce;
    }

    private function resolveTokenDecimals(string $tokenContract): int
    {
        foreach ($this->config['tokens'] ?? [] as $token) {
            if (strcasecmp($token['contract'] ?? '', $tokenContract) === 0 && isset($token['decimals'])) {
                return (int) $token['decimals'];
            }
        }

        try {
            $response = $this->rpc->call('eth_call', [['to' => $tokenContract, 'data' => '0x313ce567'], 'latest']);
            $hex = $response['result'] ?? '';
            if (is_string($hex) && preg_match('/^0x[0-9a-f]{64}$/iD', $hex)) {
                $decimals = gmp_init($hex, 16);
                if (gmp_cmp($decimals, 255) <= 0) {
                    return gmp_intval($decimals);
                }
            }
        } catch (\Throwable $exception) {
            throw new \RuntimeException("Cannot determine decimals for token contract [{$tokenContract}]. Please configure decimals in config/blockchainsdk.php.", 0, $exception);
        }

        throw new \RuntimeException("Cannot determine decimals for token contract [{$tokenContract}]. Please configure decimals in config/blockchainsdk.php.");
    }

    public function sendTransaction(array $params): TransactionResult
    {
        $fromPrivateKey = $params['from_private_key'] ?? $params['private_key'] ?? '';
        $from = $this->generator->privateKeyToAddress($fromPrivateKey);
        $params['from_private_key'] = $fromPrivateKey;
        $params['private_key'] = $fromPrivateKey;

        $params['nonce'] = $params['nonce'] ?? $this->getNextNonce($from);

        $gasPriceRes = $this->rpc->call('eth_gasPrice', []);
        $gasPriceWei = gmp_strval(gmp_init($gasPriceRes['result'] ?? '0x4a817c800', 16), 10);
        $params['gas_price'] = $params['gas_price'] ?? $gasPriceWei;

        $tokenContract = $params['token_contract'] ?? null;
        if ($tokenContract && empty($params['data'])) {
            $recipient = $params['to'];
            $amountRaw = (string) ($params['amount_raw'] ?? Decimal::toBaseUnit(
                $params['amount'] ?? '0',
                (int) ($params['decimals'] ?? $this->resolveTokenDecimals($tokenContract))
            ));
            $params['to'] = $tokenContract;
            $params['data'] = EvmTransactionSigner::buildErc20TransferData($recipient, $amountRaw);
            $params['value'] = '0';
        } elseif (isset($params['amount']) && ! isset($params['value'])) {
            $params['value'] = Decimal::toBaseUnit($params['amount'], 18);
        }

        // Strict gas estimation with revert detection
        if (! isset($params['gas_limit'])) {
            try {
                $estimateParams = [
                    'from' => $from,
                    'to' => $params['to'],
                    'value' => '0x'.gmp_strval(gmp_init($params['value'] ?? '0', 10), 16),
                ];
                if (! empty($params['data'])) {
                    $estimateParams['data'] = $params['data'];
                }
                $estRes = $this->rpc->call('eth_estimateGas', [$estimateParams]);
                $estimatedGas = hexdec($estRes['result'] ?? '0x0');
                if ($estimatedGas > 0) {
                    $params['gas_limit'] = min((int) ($estimatedGas * 1.20), 500000);
                }
            } catch (\Throwable $e) {
                $msg = strtolower($e->getMessage());
                // Fail closed on contract execution reverts to prevent burning gas fees
                if (str_contains($msg, 'revert') || str_contains($msg, 'insufficient') || str_contains($msg, 'exceeds balance') || str_contains($msg, 'allowance')) {
                    return new TransactionResult(false, null, null, 'Transaction execution rejected: '.$e->getMessage());
                }

                // Fallback for node transport errors
                $params['gas_limit'] = ! empty($params['data']) ? 80000 : 21000;
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
            if (str_contains((string) $sweepAmount, '.')) {
                throw new \InvalidArgumentException(
                    'sweep() amount must be an integer base unit string (wei). '.
                    "Received \"{$sweepAmount}\". Use Decimal::toBaseUnit() to convert human-readable amounts."
                );
            }

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

        // Recipient may be a contract or EIP-7702 delegated EOA that needs more than a
        // plain 21000 transfer, so estimate against the real destination before reserving fees.
        $gasLimit = $this->estimateNativeTransferGasLimit($from, $toAddress, $balance->balanceRaw);
        $totalGasFee = bcmul($gasPriceWei, (string) $gasLimit);
        // Add 10% safety buffer for gas price fluctuations
        $gasFeeWithBuffer = bcadd($totalGasFee, bcdiv($totalGasFee, '10', 0));

        $sweepable = bcsub($balance->balanceRaw, $gasFeeWithBuffer);
        if (bccomp($sweepable, '0') <= 0) {
            return new TransactionResult(false, null, null, 'Insufficient native balance to cover transaction gas fee.');
        }

        return $this->sendTransaction([
            'from_private_key' => $fromPrivateKey,
            'to' => $toAddress,
            'value' => $sweepable,
            'gas_limit' => $gasLimit,
            'gas_price' => $gasPriceWei,
        ]);
    }

    /**
     * Estimate the gas limit for a native currency transfer, falling back to a plain
     * EOA-to-EOA limit only when estimation is unavailable. Destinations that are
     * contracts or EIP-7702 delegated EOAs can require significantly more than 21000.
     */
    private function estimateNativeTransferGasLimit(string $from, string $toAddress, string $valueRaw): int
    {
        try {
            $estRes = $this->rpc->call('eth_estimateGas', [[
                'from' => $from,
                'to' => $toAddress,
                'value' => '0x'.gmp_strval(gmp_init($valueRaw, 10), 16),
            ]]);
            $estimatedGas = hexdec($estRes['result'] ?? '0x0');
            if ($estimatedGas > 0) {
                return min((int) ($estimatedGas * 1.20), 500000);
            }
        } catch (\Throwable $e) {
            // Fall back to the plain transfer limit on transport/estimation errors.
        }

        return 21000;
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
            return new TransactionResult(true, null, null, 'Sub-wallet already has sufficient gas.');
        }

        $deficitWei = bcsub($requiredGasWei, $currentBalanceWei);
        // Add 10% safety buffer for gas price fluctuations
        $fuelAmountWei = bcadd($deficitWei, bcdiv($deficitWei, '10', 0));

        $from = $this->generator->privateKeyToAddress($masterGasPrivateKey);
        $gasLimit = $this->estimateNativeTransferGasLimit($from, $subWalletAddress, $fuelAmountWei);

        return $this->sendTransaction([
            'from_private_key' => $masterGasPrivateKey,
            'to' => $subWalletAddress,
            'value' => $fuelAmountWei,
            'gas_limit' => $gasLimit,
        ]);
    }

    public function getTransactionReceipt(string $txHash): ?TransactionReceipt
    {
        try {
            $res = $this->rpc->call('eth_getTransactionReceipt', [$txHash]);
            $receipt = $res['result'] ?? null;
            if (! empty($receipt) && is_array($receipt)) {
                $blockNumber = hexdec($receipt['blockNumber'] ?? '0x0');
                $gasUsed = gmp_strval(gmp_init($receipt['gasUsed'] ?? '0x0', 16), 10);
                $effectiveGasPrice = gmp_strval(gmp_init($receipt['effectiveGasPrice'] ?? $receipt['gasPrice'] ?? '0x0', 16), 10);
                $status = $receipt['status'] ?? '0x1';
                $isSuccessful = ($status === '0x1' || $status === '1' || $status === 1);

                return new TransactionReceipt(
                    txHash: $txHash,
                    blockNumber: $blockNumber,
                    gasUsed: $gasUsed,
                    effectiveGasPrice: $effectiveGasPrice,
                    isSuccessful: $isSuccessful,
                    logs: $receipt['logs'] ?? [],
                    raw: $receipt
                );
            }
        } catch (\Throwable $e) {
            // Not yet mined or node error
        }

        return null;
    }

    public function waitForTransactionReceipt(string $txHash, int $timeoutSeconds = 60, int $pollIntervalMs = 2000): TransactionReceipt|array|null
    {
        $timeout = $timeoutSeconds > 0 ? $timeoutSeconds : match ($this->chainId) {
            56, 137, 8453, 42161, 10 => 15, // Fast L2s & BSC: 15s ceiling
            default => 30, // Ethereum L1: 30s ceiling
        };

        $startTime = time();
        while ((time() - $startTime) < $timeout) {
            $receipt = $this->getTransactionReceipt($txHash);
            if ($receipt !== null) {
                if (! $receipt->isSuccessful) {
                    throw new \RuntimeException("Transaction {$txHash} reverted on-chain.");
                }

                return $receipt;
            }
            usleep($pollIntervalMs * 1000);
        }

        return null;
    }

    public function sweepTokenWithGasSponsorship(string $subWalletPrivateKey, string $masterGasPrivateKey, string $toVaultAddress, string $tokenContract, ?string $amount = null): TransactionResult
    {
        $fromAddress = $this->generator->privateKeyToAddress($subWalletPrivateKey);

        // 1. Ensure sub-wallet is fueled with native gas
        $fuelResult = $this->fuelSubWallet($masterGasPrivateKey, $fromAddress, $tokenContract);
        if (! $fuelResult->success && empty($fuelResult->txHash)) {
            return new TransactionResult(false, null, null, 'Failed to fuel sub-wallet with gas: '.($fuelResult->errorMessage ?? 'Unknown error'));
        }

        // 2. If gas funding was broadcast, wait for on-chain receipt before sweeping
        if (! empty($fuelResult->txHash)) {
            try {
                $receipt = $this->waitForTransactionReceipt($fuelResult->txHash);
                if (! $receipt) {
                    return new TransactionResult(false, null, null, "Gas funding tx {$fuelResult->txHash} timed out waiting for on-chain receipt.");
                }
            } catch (\Throwable $e) {
                return new TransactionResult(false, null, null, 'Gas funding failed: '.$e->getMessage());
            }
        }

        // 3. Verify sub-wallet has enough gas balance to execute the ERC-20 transfer
        $gasBalanceWei = $this->getBalance($fromAddress)->balanceRaw;
        if (bccomp($gasBalanceWei, '0') <= 0) {
            return new TransactionResult(false, null, null, 'Sub-wallet gas balance is 0 after funding attempt.');
        }

        // 4. Execute ERC-20 token sweep
        return $this->sweep($subWalletPrivateKey, $toVaultAddress, $tokenContract, $amount);
    }

    public function broadcastRawTransaction(string $signedRawTx): TransactionResult
    {
        try {
            $res = $this->rpc->call('eth_sendRawTransaction', [$signedRawTx]);
            $txHash = $res['result'] ?? null;

            return new TransactionResult(
                success: ! empty($txHash),
                txHash: $txHash,
                rawSignedHex: $signedRawTx,
                errorMessage: $res['error']['message'] ?? null
            );
        } catch (\Throwable $e) {
            return new TransactionResult(false, null, $signedRawTx, $e->getMessage());
        }
    }

    public function getLatestIncomingTransaction(string $address, ?string $tokenContract = null, int $decimals = 18): ?array
    {
        $transfers = $this->getIncomingTransactions($address, $tokenContract, $decimals, 1);

        return $transfers[0] ?? null;
    }

    /**
     * Retrieve incoming transfer events with exact amounts, base units, log indices, and confirmations.
     *
     * @return array<int, array{tx_hash: string, log_index: int, block_number: int, from_address: ?string, to_address: string, amount_raw: string, amount: string, decimals: int, confirmations: int}>
     */
    public function getIncomingTransactions(string $address, ?string $tokenContract = null, int $decimals = 18, int $limit = 10): array
    {
        $results = [];

        try {
            $currentBlockHex = $this->rpc->call('eth_blockNumber', [])['result'] ?? null;
            $currentBlock = $currentBlockHex ? hexdec($currentBlockHex) : 0;

            // 1. Direct Explorer API (Blockscout)
            $blockscoutHosts = [
                1 => 'eth.blockscout.com',
                10 => 'optimism.blockscout.com',
                56 => 'bsc.blockscout.com',
                137 => 'polygon.blockscout.com',
                8453 => 'base.blockscout.com',
                42161 => 'arbitrum.blockscout.com',
                42220 => 'celo.blockscout.com',
                534352 => 'scroll.blockscout.com',
            ];

            if (isset($blockscoutHosts[$this->chainId])) {
                $host = $blockscoutHosts[$this->chainId];
                $client = new Client(['timeout' => 4, 'http_errors' => false]);

                if ($tokenContract) {
                    $url = "https://{$host}/api/v2/addresses/{$address}/token-transfers";
                    $res = $client->get($url);
                    if ($res->getStatusCode() === 200) {
                        $data = json_decode($res->getBody()->getContents(), true);
                        foreach ($data['items'] ?? [] as $item) {
                            $to = strtolower($item['to']['hash'] ?? '');
                            if ($to === strtolower($address)) {
                                $itemContract = strtolower($item['token']['address'] ?? '');
                                if (! $tokenContract || $itemContract === strtolower($tokenContract)) {
                                    $txHash = $item['transaction_hash'] ?? null;
                                    $from = $item['from']['hash'] ?? null;
                                    $rawVal = (string) ($item['total']['value'] ?? '0');
                                    $itemDecimals = (int) ($item['token']['decimals'] ?? $decimals);
                                    $blockNumber = (int) ($item['block_number'] ?? $currentBlock);
                                    $logIndex = (int) ($item['log_index'] ?? 0);
                                    $confirmations = $currentBlock && $blockNumber ? max(1, $currentBlock - $blockNumber + 1) : 1;

                                    if ($txHash) {
                                        $formatted = bcpow('10', $itemDecimals) !== '0'
                                            ? bcdiv($rawVal, bcpow('10', $itemDecimals), 8)
                                            : '0';

                                        $results[] = [
                                            'tx_hash' => $txHash,
                                            'log_index' => $logIndex,
                                            'block_number' => $blockNumber,
                                            'from_address' => $from,
                                            'to_address' => $address,
                                            'amount_raw' => $rawVal,
                                            'amount' => $formatted,
                                            'decimals' => $itemDecimals,
                                            'confirmations' => $confirmations,
                                        ];

                                        if (count($results) >= $limit) {
                                            return $results;
                                        }
                                    }
                                }
                            }
                        }
                    }
                } else {
                    $url = "https://{$host}/api/v2/addresses/{$address}/transactions";
                    $res = $client->get($url);
                    if ($res->getStatusCode() === 200) {
                        $data = json_decode($res->getBody()->getContents(), true);
                        foreach ($data['items'] ?? [] as $item) {
                            $to = strtolower($item['to']['hash'] ?? '');
                            if ($to === strtolower($address) && ($item['status'] ?? '') === 'ok') {
                                $rawVal = (string) ($item['value'] ?? '0');
                                $blockNumber = (int) ($item['block_number'] ?? $currentBlock);
                                $confirmations = $currentBlock && $blockNumber ? max(1, $currentBlock - $blockNumber + 1) : 1;

                                $formatted = bcdiv($rawVal, bcpow('10', 18), 8);

                                $results[] = [
                                    'tx_hash' => $item['hash'] ?? '',
                                    'log_index' => 0,
                                    'block_number' => $blockNumber,
                                    'from_address' => $item['from']['hash'] ?? null,
                                    'to_address' => $address,
                                    'amount_raw' => $rawVal,
                                    'amount' => $formatted,
                                    'decimals' => 18,
                                    'confirmations' => $confirmations,
                                ];

                                if (count($results) >= $limit) {
                                    return $results;
                                }
                            }
                        }
                    }
                }
            }

            // 2. RPC-based eth_getLogs for ERC-20 tokens
            if ($tokenContract && empty($results) && $currentBlock > 0) {
                $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
                $paddedAddress = '0x'.str_pad(substr(strtolower($address), 2), 64, '0', STR_PAD_LEFT);
                $fromBlock = max(0, $currentBlock - 2000);

                $logs = $this->getLogsChunked($fromBlock, $currentBlock, [
                    'address' => $tokenContract,
                    'topics' => [$transferTopic, null, $paddedAddress],
                ]);

                if (! empty($logs) && is_array($logs)) {
                    foreach (array_reverse($logs) as $log) {
                        $txHash = $log['transactionHash'] ?? null;
                        if (! $txHash) {
                            continue;
                        }

                        $fromTopic = $log['topics'][1] ?? null;
                        $fromAddress = $fromTopic ? ('0x'.substr($fromTopic, 26)) : null;
                        $rawHex = $log['data'] ?? '0x0';
                        $rawVal = gmp_strval(gmp_init($rawHex, 16), 10);
                        $blockNumber = hexdec($log['blockNumber'] ?? '0x0');
                        $logIndex = hexdec($log['logIndex'] ?? '0x0');
                        $confirmations = max(1, $currentBlock - $blockNumber + 1);

                        $formatted = bcpow('10', $decimals) !== '0'
                            ? bcdiv($rawVal, bcpow('10', $decimals), 8)
                            : '0';

                        $results[] = [
                            'tx_hash' => $txHash,
                            'log_index' => $logIndex,
                            'block_number' => $blockNumber,
                            'from_address' => $fromAddress,
                            'to_address' => $address,
                            'amount_raw' => $rawVal,
                            'amount' => $formatted,
                            'decimals' => $decimals,
                            'confirmations' => $confirmations,
                        ];

                        if (count($results) >= $limit) {
                            return $results;
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Graceful fallback
        }

        return $results;
    }

    public function getLatestIncomingTxHash(string $address, ?string $tokenContract = null): ?string
    {
        $tx = $this->getLatestIncomingTransaction($address, $tokenContract);

        return $tx['tx_hash'] ?? null;
    }

    /**
     * Chunked eth_getLogs executor to respect provider size limits (e.g. Alchemy 10-block limit).
     */
    public function getLogsChunked(int $fromBlock, int $toBlock, array $filter): array
    {
        $chunkSize = (int) ($this->config['log_chunk_size'] ?? 2000);
        if ($chunkSize <= 0) {
            $chunkSize = 2000;
        }

        $allLogs = [];
        for ($start = $fromBlock; $start <= $toBlock; $start += $chunkSize) {
            $end = min($start + $chunkSize - 1, $toBlock);
            $chunkFilter = $filter;
            $chunkFilter['fromBlock'] = '0x'.dechex($start);
            $chunkFilter['toBlock'] = '0x'.dechex($end);

            try {
                $res = $this->rpc->call('eth_getLogs', [$chunkFilter]);
                $logs = $res['result'] ?? [];
                if (! empty($logs) && is_array($logs)) {
                    array_push($allLogs, ...$logs);
                }
            } catch (\Throwable $e) {
                // Continue scanning remaining chunks
            }
        }

        return $allLogs;
    }

    /**
     * Retrieve all ERC-20 Transfer events in a block range.
     *
     * @return array<int, array{tx_hash: string, log_index: int, block_number: int, from_address: string, to_address: string, contract: ?string, amount_raw: string, amount: string, decimals: int}>
     */
    public function getTransferLogs(
        int $fromBlock,
        int $toBlock,
        ?string $tokenContract = null,
        ?int $decimals = 18
    ): array {
        $transferTopic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $filter = [
            'topics' => [$transferTopic],
        ];

        if ($tokenContract) {
            $filter['address'] = $tokenContract;
        }

        $logs = $this->getLogsChunked($fromBlock, $toBlock, $filter);
        $results = [];

        foreach ($logs as $log) {
            $fromTopic = $log['topics'][1] ?? null;
            $toTopic = $log['topics'][2] ?? null;

            if (! $fromTopic || ! $toTopic) {
                continue;
            }

            $txHash = $log['transactionHash'] ?? null;
            if (! $txHash) {
                continue;
            }

            $rawHex = $log['data'] ?? '0x0';
            $rawVal = gmp_strval(gmp_init($rawHex, 16), 10);
            $logDecimals = $decimals ?? 18;

            $formatted = bcpow('10', (string) $logDecimals) !== '0'
                ? bcdiv($rawVal, bcpow('10', (string) $logDecimals), 8)
                : '0';

            $results[] = [
                'tx_hash' => $txHash,
                'log_index' => hexdec($log['logIndex'] ?? '0x0'),
                'block_number' => hexdec($log['blockNumber'] ?? '0x0'),
                'from_address' => '0x'.substr($fromTopic, 26),
                'to_address' => '0x'.substr($toTopic, 26),
                'contract' => $log['address'] ?? $tokenContract,
                'amount_raw' => $rawVal,
                'amount' => $formatted,
                'decimals' => $logDecimals,
            ];
        }

        return $results;
    }

    public function getRpc(): RpcClient
    {
        return $this->rpc;
    }
}
