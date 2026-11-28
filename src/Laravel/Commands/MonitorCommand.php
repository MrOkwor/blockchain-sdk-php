<?php

namespace BlockchainSdk\Laravel\Commands;

use App\Models\BlockchainSdkDeposit;
use App\Models\BlockchainSdkWallet;
use BlockchainSdk\Drivers\Litecoin\LitecoinDriver;
use BlockchainSdk\Drivers\Xrp\XrpDriver;
use BlockchainSdk\Laravel\Events\DepositConfirmed;
use BlockchainSdk\Laravel\Events\DepositDetected;
use BlockchainSdk\Laravel\Facades\Blockchain;
use BlockchainSdk\Services\AddressIndex\AddressIndexFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorCommand extends Command
{
    protected $signature = 'blockchainsdk:monitor 
                            {network? : Target specific blockchain network (bsc, ethereum, polygon, solana, tron, etc.)} 
                            {--network= : Target specific blockchain network} 
                            {--token= : Target a specific token symbol or contract address} 
                            {--once : Execute a single pass and exit (default behavior)}
                            {--strategy=per-wallet : Deposit scanning strategy: "per-wallet" (default) or "block-ingest"}';

    protected $description = 'Scan sub-wallets for incoming on-chain deposits and record confirmed deposits';

    public function handle(): int
    {
        $networkInput = $this->argument('network') ?? $this->option('network');
        $tokenInput = $this->option('token');
        $strategy = strtolower((string) $this->option('strategy'));

        $walletModel = config('blockchainsdk.models.wallet', BlockchainSdkWallet::class);
        $depositModel = config('blockchainsdk.models.deposit', BlockchainSdkDeposit::class);

        if (! class_exists($walletModel) || ! class_exists($depositModel)) {
            $this->error("BlockchainSdk models not found. Run 'php artisan vendor:publish --tag=blockchainsdk-models'");

            return self::FAILURE;
        }

        $networks = $networkInput ? [strtolower($networkInput)] : Blockchain::getAvailableNetworks();

        $this->line('Starting BlockchainSdk Deposit Monitor (Single Pass)...');

        $totalDetected = 0;

        foreach ($networks as $network) {
            try {
                $driver = Blockchain::driver($network);
            } catch (\Throwable $e) {
                continue;
            }

            $wallets = $walletModel::where('network', $network)
                ->where('is_active', true)
                ->get();

            if ($wallets->isEmpty()) {
                continue;
            }

            $tokens = $tokenInput
                ? [Blockchain::findToken($network, $tokenInput, true)]
                : Blockchain::getSupportedTokens($network, true);

            $requiredConfirmations = (int) config("blockchainsdk.confirmations.{$network}", match ($network) {
                'ethereum' => 12,
                'polygon' => 5,
                'bsc' => 3,
                'tron' => 19,
                'bitcoin' => 2,
                'litecoin' => 6,
                'xrp' => 1,
                default => 1,
            });

            $scanAllPages = $driver instanceof LitecoinDriver || $driver instanceof XrpDriver;

            // 1. Process pending unconfirmed deposits first to advance their confirmations
            $pendingDeposits = $depositModel::where('network', $network)
                ->where('status', 'pending')
                ->get();

            foreach ($pendingDeposits as $pendingDeposit) {
                try {
                    $transfers = $driver instanceof LitecoinDriver
                        ? $driver->getIncomingTransactionOutputs($pendingDeposit->to_address, $pendingDeposit->tx_hash)
                        : $driver->getIncomingTransactions($pendingDeposit->to_address, $pendingDeposit->token_contract, (int) ($pendingDeposit->decimals ?? 18), $scanAllPages ? PHP_INT_MAX : 5);

                    foreach ($transfers as $tx) {
                        $txLogIndex = (int) ($tx['log_index'] ?? 0);
                        $depositLogIndex = (int) ($pendingDeposit->log_index ?? 0);

                        if ($tx['tx_hash'] === $pendingDeposit->tx_hash && $txLogIndex === $depositLogIndex) {
                            $confs = (int) ($tx['confirmations'] ?? 1);
                            $pendingDeposit->confirmations = $confs;
                            $pendingDeposit->block_number = $tx['block_number'] ?? $pendingDeposit->block_number;

                            if ($confs >= $requiredConfirmations) {
                                $pendingDeposit->status = 'confirmed';
                                $pendingDeposit->save();
                                $this->info("✓ Deposit reached finality on [{$network}]: {$pendingDeposit->amount} {$pendingDeposit->token_symbol} (Tx: {$pendingDeposit->tx_hash}, Log: {$depositLogIndex}, Confs: {$confs})");
                                event(new DepositConfirmed($pendingDeposit));
                            } else {
                                $pendingDeposit->save();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Error updating confirmations for deposit #{$pendingDeposit->id}: ".$e->getMessage());
                }
            }

            // 2. High-performance block-level event ingestion strategy (O(blocks) instead of O(wallets * tokens))
            if ($strategy === 'block-ingest' && method_exists($driver, 'getTransferLogs')) {
                $addressIndex = AddressIndexFactory::make($network, config('blockchainsdk.address_index', []));
                $addressMap = $wallets->pluck('address', 'id')->all();
                $addressIndex->sync($addressMap);

                try {
                    $currentBlockHex = method_exists($driver, 'getRpc') ? ($driver->getRpc()->call('eth_blockNumber', [])['result'] ?? null) : null;
                    $currentBlock = $currentBlockHex ? hexdec($currentBlockHex) : 0;

                    if ($currentBlock > 0) {
                        $checkpointKey = "blockchainsdk:checkpoint:{$network}";
                        $fromBlock = Cache::get($checkpointKey);
                        if ($fromBlock === null || $fromBlock <= 0) {
                            $fromBlock = max(0, $currentBlock - 40);
                        } else {
                            $fromBlock = (int) $fromBlock + 1;
                        }

                        if ($fromBlock <= $currentBlock) {
                            $this->line("Scanning block range #{$fromBlock} to #{$currentBlock} on [{$network}] (block-ingest)...");

                            foreach ($tokens as $symKey => $token) {
                                if (! $token) {
                                    continue;
                                }

                                $tokenContract = $token['contract'] ?? null;
                                $tokenSymbol = ! empty($token['symbol']) ? strtoupper($token['symbol']) : (is_string($symKey) ? strtoupper($symKey) : 'TOKEN');
                                $tokenDecimals = (int) ($token['decimals'] ?? 18);

                                $logs = $driver->getTransferLogs($fromBlock, $currentBlock, $tokenContract, $tokenDecimals);

                                foreach ($logs as $transfer) {
                                    $toAddress = $transfer['to_address'] ?? '';
                                    if (! $addressIndex->contains($toAddress)) {
                                        continue;
                                    }

                                    $walletId = $addressIndex->resolve($toAddress) ?? $wallets->firstWhere('address', $toAddress)?->id;
                                    if (! $walletId) {
                                        continue;
                                    }

                                    $txHash = $transfer['tx_hash'];
                                    $logIndex = (int) ($transfer['log_index'] ?? 0);
                                    $blockNum = (int) ($transfer['block_number'] ?? 0);
                                    $amountRaw = (string) ($transfer['amount_raw'] ?? '0');
                                    $amountDecimal = (string) ($transfer['amount'] ?? '0.00000000');
                                    $confs = max(1, $currentBlock - $blockNum + 1);
                                    $isFinal = $confs >= $requiredConfirmations;
                                    $status = $isFinal ? 'confirmed' : 'pending';

                                    $existing = $depositModel::where('network', $network)
                                        ->where('tx_hash', $txHash)
                                        ->where('log_index', $logIndex)
                                        ->first();

                                    if (! $existing && bccomp($amountRaw, '0') > 0) {
                                        $deposit = $depositModel::create([
                                            'wallet_id' => $walletId,
                                            'network' => $network,
                                            'tx_hash' => $txHash,
                                            'log_index' => $logIndex,
                                            'block_number' => $blockNum,
                                            'from_address' => $transfer['from_address'] ?? null,
                                            'to_address' => $toAddress,
                                            'token_symbol' => $tokenSymbol,
                                            'token_contract' => $tokenContract,
                                            'amount_raw' => $amountRaw,
                                            'amount' => $amountDecimal,
                                            'decimals' => $tokenDecimals,
                                            'confirmations' => $confs,
                                            'status' => $status,
                                            'is_credited' => false,
                                            'is_swept' => false,
                                        ]);

                                        $this->info('✓ '.($isFinal ? 'Confirmed' : 'Detected')." block-ingest deposit on [{$network}]: {$amountDecimal} {$tokenSymbol} on {$toAddress} (Tx: {$txHash}#{$logIndex}, Confs: {$confs})");

                                        if ($isFinal) {
                                            event(new DepositConfirmed($deposit));
                                        } else {
                                            event(new DepositDetected($deposit));
                                        }

                                        $totalDetected++;
                                    }
                                }
                            }

                            // Update resumable checkpoint
                            Cache::put($checkpointKey, $currentBlock, now()->addDays(30));
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Block-ingest monitor error on [{$network}]: ".$e->getMessage());
                    $this->warn("Block-ingest scan notice on [{$network}]: ".$e->getMessage());
                }

                continue;
            }

            // 3. Scan wallets for new transfer events (per-wallet fallback strategy)
            foreach ($wallets as $wallet) {
                // A. Scan configured tokens
                foreach ($tokens as $symKey => $token) {
                    if (! $token) {
                        continue;
                    }

                    $tokenContract = $token['contract'] ?? null;
                    $tokenSymbol = ! empty($token['symbol']) ? strtoupper($token['symbol']) : (is_string($symKey) ? strtoupper($symKey) : 'TOKEN');
                    $tokenDecimals = (int) ($token['decimals'] ?? 18);

                    try {
                        $incomingTransfers = $driver->getIncomingTransactions($wallet->address, $tokenContract, $tokenDecimals, $scanAllPages ? PHP_INT_MAX : 10);

                        foreach ($incomingTransfers as $transfer) {
                            $txHash = $transfer['tx_hash'];
                            if (empty($txHash)) {
                                continue;
                            }

                            $logIndex = (int) ($transfer['log_index'] ?? 0);
                            $blockNumber = isset($transfer['block_number']) ? (int) $transfer['block_number'] : null;
                            $fromAddress = $transfer['from_address'] ?? null;
                            $amountRaw = (string) ($transfer['amount_raw'] ?? '0');
                            $amountDecimal = (string) ($transfer['amount'] ?? '0.00000000');
                            $confirmations = (int) ($transfer['confirmations'] ?? 1);
                            $isFinal = $confirmations >= $requiredConfirmations;
                            $status = $isFinal ? 'confirmed' : 'pending';

                            // Immutable blockchain transfer identity: network + tx_hash + log_index
                            $existing = $depositModel::where('network', $network)
                                ->where('tx_hash', $txHash)
                                ->where('log_index', $logIndex)
                                ->first();

                            // Arbitrary-precision zero check (ACC-04a)
                            if (! $existing && bccomp($amountRaw, '0') > 0) {
                                $deposit = $depositModel::create([
                                    'wallet_id' => $wallet->id,
                                    'network' => $network,
                                    'tx_hash' => $txHash,
                                    'log_index' => $logIndex,
                                    'block_number' => $blockNumber,
                                    'from_address' => $fromAddress,
                                    'to_address' => $wallet->address,
                                    'token_symbol' => $tokenSymbol,
                                    'token_contract' => $tokenContract,
                                    'amount_raw' => $amountRaw,
                                    'amount' => $amountDecimal,
                                    'decimals' => $tokenDecimals,
                                    'confirmations' => $confirmations,
                                    'status' => $status,
                                    'is_credited' => false,
                                    'is_swept' => false,
                                ]);

                                $this->info('✓ '.($isFinal ? 'Confirmed' : 'Detected')." deposit on [{$network}]: {$amountDecimal} {$tokenSymbol} on {$wallet->address} (Tx: {$txHash}#{$logIndex}, Confs: {$confirmations})");

                                if ($isFinal) {
                                    event(new DepositConfirmed($deposit));
                                } else {
                                    event(new DepositDetected($deposit));
                                }

                                $totalDetected++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Deposit monitor error on {$wallet->address} ({$tokenSymbol}): ".$e->getMessage());
                    }
                }

                // B. Scan native currency transfers
                try {
                    $nativeDecimals = match ($network) {
                        'tron', 'xrp' => 6,
                        'solana' => 9,
                        'bitcoin', 'litecoin' => 8,
                        default => 18,
                    };
                    $nativeTransfers = $driver->getIncomingTransactions($wallet->address, null, $nativeDecimals, $scanAllPages ? PHP_INT_MAX : 5);

                    foreach ($nativeTransfers as $transfer) {
                        $txHash = $transfer['tx_hash'];
                        if (empty($txHash)) {
                            continue;
                        }

                        $logIndex = (int) ($transfer['log_index'] ?? 0);
                        $blockNumber = isset($transfer['block_number']) ? (int) $transfer['block_number'] : null;
                        $fromAddress = $transfer['from_address'] ?? null;
                        $amountRaw = (string) ($transfer['amount_raw'] ?? '0');
                        $amountDecimal = (string) ($transfer['amount'] ?? '0.00000000');
                        $confirmations = (int) ($transfer['confirmations'] ?? 1);
                        $isFinal = $confirmations >= $requiredConfirmations;
                        $status = $isFinal ? 'confirmed' : 'pending';

                        $existing = $depositModel::where('network', $network)
                            ->where('tx_hash', $txHash)
                            ->where('log_index', $logIndex)
                            ->first();

                        $minimumRaw = $nativeDecimals === 18 ? '100000000000000' : '0';
                        if (! $existing && bccomp($amountRaw, $minimumRaw) > 0) {
                            $deposit = $depositModel::create([
                                'wallet_id' => $wallet->id,
                                'network' => $network,
                                'tx_hash' => $txHash,
                                'log_index' => $logIndex,
                                'block_number' => $blockNumber,
                                'from_address' => $fromAddress,
                                'to_address' => $wallet->address,
                                'token_symbol' => 'NATIVE',
                                'token_contract' => null,
                                'amount_raw' => $amountRaw,
                                'amount' => $amountDecimal,
                                'decimals' => $nativeDecimals,
                                'confirmations' => $confirmations,
                                'status' => $status,
                                'is_credited' => false,
                                'is_swept' => false,
                            ]);

                            $this->info('✓ '.($isFinal ? 'Confirmed' : 'Detected')." native deposit on [{$network}]: {$amountDecimal} on {$wallet->address} (Tx: {$txHash})");

                            if ($isFinal) {
                                event(new DepositConfirmed($deposit));
                            } else {
                                event(new DepositDetected($deposit));
                            }

                            $totalDetected++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Native deposit monitor error on {$wallet->address}: ".$e->getMessage());
                }
            }
        }

        $this->line("Deposit scan completed. {$totalDetected} new deposits processed.");

        return self::SUCCESS;
    }
}
