<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Events\WalletSwept;
use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SweepCommand extends Command
{
    protected $signature = 'blockchainsdk:sweep 
                            {network? : Target blockchain network (ethereum, bsc, polygon, arbitrum, solana, bitcoin, tron, etc.)} 
                            {--network= : Target blockchain network} 
                            {--token= : Optional token symbol or contract address} 
                            {--sponsor : Automatically sponsor/fuel native gas from master gas station if sub-wallet is dry}
                            {--credit : Automatically record sweep in database and dispatch WalletSwept event to give value to user}';

    protected $description = 'Sweep customer sub-wallet balances into central cold/master vaults';

    public function handle(): int
    {
        $network = strtolower($this->argument('network') ?? $this->option('network') ?? config('blockchainsdk.default', 'ethereum'));
        $tokenInput = $this->option('token');
        $shouldSponsor = (bool)$this->option('sponsor');
        $shouldCredit = (bool)$this->option('credit');

        $walletModel = config('blockchainsdk.models.wallet', \App\Models\BlockchainSdkWallet::class);
        $sweepModel  = config('blockchainsdk.models.sweep', \App\Models\BlockchainSdkSweep::class);
        $depositModel = config('blockchainsdk.models.deposit', \App\Models\BlockchainSdkDeposit::class);

        if (!class_exists($walletModel) || !class_exists($sweepModel)) {
            $this->error("BlockchainSdk models not found. Run 'php artisan vendor:publish --tag=blockchainsdk-models'");
            return self::FAILURE;
        }

        $masterVault = Blockchain::getMasterWallet($network);
        if (!$masterVault) {
            $this->error("No master vault wallet configured for network [{$network}]. Set BLOCKCHAIN_MASTER_" . strtoupper($network) . " in .env or run: php artisan blockchainsdk:generate-master-wallets {$network}");
            return self::FAILURE;
        }

        $gasKey = $shouldSponsor ? Blockchain::getMasterGasKey($network) : null;
        if ($shouldSponsor && !$gasKey) {
            $this->warn("Automated gas sponsorship requested (--sponsor), but no BLOCKCHAIN_GAS_KEY_" . strtoupper($network) . " is configured.");
        }

        $driver = Blockchain::driver($network);
        $tokenContract = null;
        $tokenSymbol = 'NATIVE';

        if ($tokenInput) {
            $tokenInfo = Blockchain::findToken($network, $tokenInput, false);
            if ($tokenInfo) {
                $status = $tokenInfo['status'] ?? 'enabled';
                if ($status !== 'enabled' && $status !== true) {
                    $this->error("Token [{$tokenInfo['symbol']}] is currently disabled for sweeping on network [{$network}].");
                    return self::FAILURE;
                }

                $tokenContract = $tokenInfo['contract'] ?? $tokenInput;
                $tokenSymbol = $tokenInfo['symbol'] ?? 'TOKEN';
                $this->line("Target Token: <fg=yellow>{$tokenInfo['name']} ({$tokenSymbol})</> - Status: <fg=green>Enabled</> - Contract: {$tokenContract}");
            } else {
                $tokenContract = $tokenInput;
                $tokenSymbol = 'TOKEN';
                $this->line("Target Contract: <fg=yellow>{$tokenContract}</>");
            }
        } else {
            $this->line("Target Asset: <fg=green>Native Currency</>");
        }

        $this->line("Destination Master Vault: <fg=cyan>{$masterVault}</>");

        $wallets = $walletModel::where('network', $network)
            ->where('is_active', true)
            ->get();

        if ($wallets->isEmpty()) {
            $this->line("No active wallets found for network [{$network}].");
            return self::SUCCESS;
        }

        $this->line("Scanning {$wallets->count()} active sub-wallets on [{$network}]...");

        $sweptCount = 0;

        foreach ($wallets as $wallet) {
            try {
                $balance = $driver->getBalance($wallet->address, $tokenContract);
                $balanceAmount = (float)$balance->balanceFormatted;

                if ($balanceAmount <= 0) {
                    continue;
                }

                $this->info("Found balance: {$balanceAmount} {$tokenSymbol} on [{$wallet->address}]. Sweeping...");

                if ($tokenContract && $shouldSponsor && $gasKey) {
                    $result = $driver->sweepTokenWithGasSponsorship(
                        subWalletPrivateKey: $wallet->private_key,
                        masterGasPrivateKey: $gasKey,
                        toVaultAddress:      $masterVault,
                        tokenContract:       $tokenContract
                    );
                } else {
                    $result = $driver->sweep(
                        fromPrivateKey: $wallet->private_key,
                        toAddress:      $masterVault,
                        tokenContract:  $tokenContract
                    );
                }

                if ($result->success && !empty($result->txHash)) {
                    $this->info("✓ Sweep broadcasted! TxHash: {$result->txHash}");

                    // Confirm on-chain receipt if supported
                    if (method_exists($driver, 'waitForTransactionReceipt')) {
                        $this->line("Waiting for on-chain sweep confirmation...");
                        try {
                            $receipt = $driver->waitForTransactionReceipt($result->txHash);
                            if ($receipt) {
                                $this->info("✓ Sweep confirmed on-chain in block #" . hexdec($receipt['blockNumber'] ?? '0x0'));
                            }
                        } catch (\Throwable $e) {
                            $this->warn("Sweep receipt check notice: " . $e->getMessage());
                        }
                    }

                    $sweepRecord = $sweepModel::create([
                        'wallet_id'        => $wallet->id,
                        'network'          => $network,
                        'from_address'     => $wallet->address,
                        'to_vault_address' => $masterVault,
                        'token_symbol'     => $tokenSymbol,
                        'token_contract'   => $tokenContract,
                        'amount'           => $balanceAmount,
                        'tx_hash'          => $result->txHash,
                        'fee_spent'        => 0,
                        'status'           => 'completed',
                        'is_credited'      => $shouldCredit,
                        'credited_at'      => $shouldCredit ? now() : null,
                    ]);

                    if (class_exists($depositModel)) {
                        $depositModel::where('wallet_id', $wallet->id)
                            ->where('is_swept', false)
                            ->update([
                                'is_swept'      => true,
                                'sweep_tx_hash' => $result->txHash,
                                'status'        => 'swept',
                                'swept_at'      => now(),
                            ]);
                    }

                    $wallet->update(['balance' => 0]);

                    event(new WalletSwept($sweepRecord));

                    $sweptCount++;
                } else {
                    $errMsg = $result->errorMessage ?? 'Unknown error';
                    $this->error("✗ Sweep failed for [{$wallet->address}]: {$errMsg}");
                    Log::error("Sweep failed for {$wallet->address}: {$errMsg}");
                }
            } catch (\Throwable $e) {
                $this->error("✗ Sweep exception for [{$wallet->address}]: " . $e->getMessage());
                Log::error("Sweep exception for {$wallet->address}: " . $e->getMessage());
            }
        }

        $this->info("Sweep pass complete. Swept {$sweptCount} sub-wallets.");
        return self::SUCCESS;
    }
}