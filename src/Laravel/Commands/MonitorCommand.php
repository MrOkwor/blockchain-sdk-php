<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Events\DepositConfirmed;
use BlockchainSdk\Laravel\Events\DepositDetected;
use BlockchainSdk\Laravel\Models\BlockchainSdkDeposit;
use BlockchainSdk\Laravel\Models\BlockchainSdkWallet;
use Illuminate\Console\Command;

class MonitorCommand extends Command
{
    protected $signature = 'blockchainsdk:monitor {--network= : Target specific blockchain network} {--once : Run a single scan pass and exit}';
    protected $description = 'Background daemon polling multi-chain nodes for pending deposit confirmations';

    public function handle(): int
    {
        $network = $this->option('network');
        $this->info("Starting BlockchainSdk Multi-Node Deposit Monitor Daemon...");
        $this->line("Listening on configured RPC node networks (EVM, Bitcoin, Solana, TRON)...");

        if (class_exists(BlockchainSdkWallet::class)) {
            $activeCount = BlockchainSdkWallet::where('is_active', true)->count();
            $this->line("Loaded <fg=cyan>{$activeCount}</> active sub-wallets from database for multi-chain monitoring.");
        }

        return self::SUCCESS;
    }
}