<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Events\DepositConfirmed;
use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorCommand extends Command
{
    protected $signature = 'blockchainsdk:monitor 
                            {network? : Target specific blockchain network (bsc, ethereum, polygon, solana, tron, etc.)} 
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

            foreach ($wallets as $wallet) {
                // 1. Scan configured tokens
                foreach ($tokens as $token) {
                    if (!$token) continue;

                    $tokenContract = $token['contract'] ?? null;
                    $tokenSymbol   = $token['symbol'] ?? 'TOKEN';

                    try {
                        $balance = $driver->getBalance($wallet->address, $tokenContract);
                        $balanceAmount = (float)$balance->balanceFormatted;

                        if ($balanceAmount > 0) {
                            $txHash = $driver->getLatestIncomingTxHash($wallet->address, $tokenContract);
                            $finalTxHash = $txHash ?: ('detected_' . substr(md5($wallet->address . $tokenSymbol . time()), 0, 16));

                            $existing = $depositModel::where('tx_hash', $finalTxHash)
                                ->orWhere(function ($q) use ($wallet, $tokenSymbol) {
                                    $q->where('wallet_id', $wallet->id)
                                      ->where('token_symbol', $tokenSymbol)
                                      ->where('is_swept', false);
                                })->first();

                            if (!$existing) {
                                $deposit = $depositModel::create([
                                    'wallet_id'      => $wallet->id,
                                    'network'        => $network,
                                    'to_address'     => $wallet->address,
                                    'token_symbol'   => $tokenSymbol,
                                    'token_contract' => $tokenContract,
                                    'amount'         => $balanceAmount,
                                    'tx_hash'        => $finalTxHash,
                                    'status'         => 'confirmed',
                                    'is_credited'    => false,
                                    'is_swept'       => false,
                                ]);

                                $this->info("✓ Deposit detected on [{$network}]: {$balanceAmount} {$tokenSymbol} on {$wallet->address} (Tx: {$finalTxHash})");
                                event(new DepositConfirmed($deposit));
                                $totalDetected++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning("Deposit monitor error on {$wallet->address} ({$tokenSymbol}): " . $e->getMessage());
                    }
                }

                // 2. Scan native currency balance
                try {
                    $nativeBalance = $driver->getBalance($wallet->address);
                    $nativeAmount = (float)$nativeBalance->balanceFormatted;

                    if ($nativeAmount > 0.001) {
                        $txHash = $driver->getLatestIncomingTxHash($wallet->address);
                        $finalTxHash = $txHash ?: ('native_' . substr(md5($wallet->address . 'NATIVE' . time()), 0, 16));

                        $existing = $depositModel::where('tx_hash', $finalTxHash)->first();
                        if (!$existing) {
                            $deposit = $depositModel::create([
                                'wallet_id'      => $wallet->id,
                                'network'        => $network,
                                'to_address'     => $wallet->address,
                                'token_symbol'   => 'NATIVE',
                                'token_contract' => null,
                                'amount'         => $nativeAmount,
                                'tx_hash'        => $finalTxHash,
                                'status'         => 'confirmed',
                                'is_credited'    => false,
                                'is_swept'       => false,
                            ]);

                            $this->info("✓ Native deposit detected on [{$network}]: {$nativeAmount} on {$wallet->address}");
                            event(new DepositConfirmed($deposit));
                            $totalDetected++;
                        }
                    }
                } catch (\Throwable $e) {
                    Log::warning("Native deposit monitor error on {$wallet->address}: " . $e->getMessage());
                }
            }
        }

        $this->line("Deposit scan completed. {$totalDetected} new deposits confirmed.");
        return self::SUCCESS;
    }
}