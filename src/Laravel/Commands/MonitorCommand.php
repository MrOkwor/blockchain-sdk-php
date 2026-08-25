<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Events\DepositConfirmed;
use BlockchainSdk\Laravel\Events\DepositDetected;
use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorCommand extends Command
{
    protected $signature = 'blockchainsdk:monitor 
                            {network? : Target specific blockchain network (bsc, ethereum, polygon, solana, tron, etc.)} 
                            {--network= : Target specific blockchain network} 
                            {--token= : Target a specific token symbol or contract address} 
                            {--once : Execute a single pass and exit (default behavior)}';

    protected $description = 'Scan sub-wallets for incoming on-chain deposits and record confirmed deposits';

    public function handle(): int
    {
        $networkInput = $this->argument('network') ?? $this->option('network');
        $tokenInput = $this->option('token');

        $walletModel = config('blockchainsdk.models.wallet', \App\Models\BlockchainSdkWallet::class);
        $depositModel = config('blockchainsdk.models.deposit', \App\Models\BlockchainSdkDeposit::class);

        if (!class_exists($walletModel) || !class_exists($depositModel)) {
            $this->error("BlockchainSdk models not found. Run 'php artisan vendor:publish --tag=blockchainsdk-models'");
            return self::FAILURE;
        }

        $networks = $networkInput ? [strtolower($networkInput)] : Blockchain::getAvailableNetworks();

        $this->line("Starting BlockchainSdk Deposit Monitor (Single Pass)...");

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

            $requiredConfirmations = (int)config("blockchainsdk.confirmations.{$network}", match($network) {
                'ethereum' => 12,
                'polygon'  => 5,
                'bsc'      => 3,
                'tron'     => 19,
                'bitcoin'  => 2,
                default    => 1,
            });

            // 1. Process pending unconfirmed deposits first to advance their confirmations
            $pendingDeposits = $depositModel::where('network', $network)
                ->where('status', 'pending')
                ->get();

            foreach ($pendingDeposits as $pendingDeposit) {
                try {
                    $transfers = method_exists($driver, 'getIncomingTransactions')
                        ? $driver->getIncomingTransactions($pendingDeposit->to_address, $pendingDeposit->token_contract, (int)($pendingDeposit->decimals ?? 18), 5)
                        : [];

                    foreach ($transfers as $tx) {
                        if ($tx['tx_hash'] === $pendingDeposit->tx_hash) {
                            $confs = $tx['confirmations'] ?? 1;
                            $pendingDeposit->confirmations = $confs;

                            if ($confs >= $requiredConfirmations) {
                                $pendingDeposit->status = 'confirmed';
                                $pendingDeposit->save();
                                $this->info("✓ Deposit reached finality on [{$network}]: {$pendingDeposit->amount} {$pendingDeposit->token_symbol} (Tx: {$pendingDeposit->tx_hash}, Confs: {$confs})");
                                event(new DepositConfirmed($pendingDeposit));
                            } else {
                                $pendingDeposit->save();
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Error updating confirmations for deposit #{$pendingDeposit->id}: " . $e->getMessage());
                }
            }

            // 2. Scan wallets for new transfer events
            foreach ($wallets as $wallet) {
                // A. Scan configured tokens
                foreach ($tokens as $symKey => $token) {
                    if (!$token) continue;

                    $tokenContract = $token['contract'] ?? null;
                    $tokenSymbol   = !empty($token['symbol']) ? strtoupper($token['symbol']) : (is_string($symKey) ? strtoupper($symKey) : 'TOKEN');
                    $tokenDecimals = (int)($token['decimals'] ?? 18);

                    try {
                        $incomingTransfers = method_exists($driver, 'getIncomingTransactions')
                            ? $driver->getIncomingTransactions($wallet->address, $tokenContract, $tokenDecimals, 10)
                            : [];

                        foreach ($incomingTransfers as $transfer) {
                            $txHash = $transfer['tx_hash'];
                            if (empty($txHash)) continue;

                            $fromAddress   = $transfer['from_address'] ?? null;
                            $amountRaw     = $transfer['amount_raw'] ?? '0';
                            $amountDecimal = $transfer['amount'] ?? '0.00000000';
                            $confirmations = $transfer['confirmations'] ?? 1;
                            $isFinal       = $confirmations >= $requiredConfirmations;
                            $status        = $isFinal ? 'confirmed' : 'pending';

                            $existing = $depositModel::where('tx_hash', $txHash)->first();

                            if (!$existing && (float)$amountDecimal > 0) {
                                $deposit = $depositModel::create([
                                    'wallet_id'      => $wallet->id,
                                    'network'        => $network,
                                    'from_address'   => $fromAddress,
                                    'to_address'     => $wallet->address,
                                    'token_symbol'   => $tokenSymbol,
                                    'token_contract' => $tokenContract,
                                    'amount'         => $amountDecimal,
                                    'confirmations'  => $confirmations,
                                    'status'         => $status,
                                    'is_credited'    => false,
                                    'is_swept'       => false,
                                ]);

                                $this->info("✓ " . ($isFinal ? "Confirmed" : "Detected") . " deposit on [{$network}]: {$amountDecimal} {$tokenSymbol} on {$wallet->address} (Tx: {$txHash}, Confs: {$confirmations})");

                                if ($isFinal) {
                                    event(new DepositConfirmed($deposit));
                                } else {
                                    event(new DepositDetected($deposit));
                                }

                                $totalDetected++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Deposit monitor error on {$wallet->address} ({$tokenSymbol}): " . $e->getMessage());
                    }
                }

                // B. Scan native currency transfers
                try {
                    $nativeTransfers = method_exists($driver, 'getIncomingTransactions')
                        ? $driver->getIncomingTransactions($wallet->address, null, 18, 5)
                        : [];

                    foreach ($nativeTransfers as $transfer) {
                        $txHash = $transfer['tx_hash'];
                        if (empty($txHash)) continue;

                        $fromAddress   = $transfer['from_address'] ?? null;
                        $amountDecimal = $transfer['amount'] ?? '0.00000000';
                        $confirmations = $transfer['confirmations'] ?? 1;
                        $isFinal       = $confirmations >= $requiredConfirmations;
                        $status        = $isFinal ? 'confirmed' : 'pending';

                        $existing = $depositModel::where('tx_hash', $txHash)->first();

                        if (!$existing && (float)$amountDecimal > 0.0001) {
                            $deposit = $depositModel::create([
                                'wallet_id'      => $wallet->id,
                                'network'        => $network,
                                'from_address'   => $fromAddress,
                                'to_address'     => $wallet->address,
                                'token_symbol'   => 'NATIVE',
                                'token_contract' => null,
                                'amount'         => $amountDecimal,
                                'confirmations'  => $confirmations,
                                'status'         => $status,
                                'is_credited'    => false,
                                'is_swept'       => false,
                            ]);

                            $this->info("✓ " . ($isFinal ? "Confirmed" : "Detected") . " native deposit on [{$network}]: {$amountDecimal} on {$wallet->address} (Tx: {$txHash})");

                            if ($isFinal) {
                                event(new DepositConfirmed($deposit));
                            } else {
                                event(new DepositDetected($deposit));
                            }

                            $totalDetected++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Native deposit monitor error on {$wallet->address}: " . $e->getMessage());
                }
            }
        }

        $this->line("Deposit scan completed. {$totalDetected} new deposits processed.");
        return self::SUCCESS;
    }
}