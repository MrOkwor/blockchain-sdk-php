<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class GenerateMasterWalletsCommand extends Command
{
    protected $signature = 'blockchainsdk:generate-master-wallets 
                            {network? : Optional target blockchain network (ethereum, bsc, polygon, solana, tron, bitcoin, etc.)}
                            {--no-encrypt : Store raw plaintext private keys instead of encrypting}
                            {--no-store : Output credentials to console without writing to .env}
                            {--force : Overwrite existing master wallet keys in .env}';

    protected $description = 'Generate master cold vault receiving addresses and hot gas station private keys, with automatic encryption and .env storage';

    public function handle(): int
    {
        $targetNetwork = $this->argument('network');
        $shouldEncrypt = !$this->option('no-encrypt');
        $shouldStore   = !$this->option('no-store');
        $force         = (bool)$this->option('force');

        $allNetworks = Blockchain::getAvailableNetworks();

        if ($targetNetwork) {
            $targetNetwork = strtolower($targetNetwork);
            if (!in_array($targetNetwork, $allNetworks)) {
                $this->error("Unsupported network [{$targetNetwork}]. Available networks: " . implode(', ', $allNetworks));
                return self::FAILURE;
            }
            $networks = [$targetNetwork];
        } else {
            $networks = $allNetworks;
        }

        $this->info("=== Blockchain SDK Master & Gas Wallet Generator ===");
        $this->line("Mode: <fg=yellow>" . ($shouldEncrypt ? 'Encrypted (AES-256-CBC/GCM)' : 'Plaintext') . "</>");
        $this->line("Storage: <fg=yellow>" . ($shouldStore ? '.env file' : 'Console output only') . "</>\n");

        $envFile = base_path('.env');
        $envContent = ($shouldStore && file_exists($envFile)) ? file_get_contents($envFile) : '';
        $envUpdates = [];
        $rows = [];

        foreach ($networks as $network) {
            $keypair = Blockchain::driver($network)->generateWallet();
            $address = $keypair->address;
            $rawPrivKey = $keypair->privateKey;

            $storedPrivKey = $shouldEncrypt ? Crypt::encryptString($rawPrivKey) : $rawPrivKey;

            $addressEnvKey = 'BLOCKCHAIN_MASTER_' . strtoupper($network);
            $gasEnvKey     = 'BLOCKCHAIN_GAS_KEY_' . strtoupper($network);

            $rows[] = [
                ucfirst($network),
                $address,
                $shouldEncrypt ? (substr($storedPrivKey, 0, 18) . '...[ENCRYPTED]') : (substr($rawPrivKey, 0, 10) . '...'),
            ];

            if ($shouldStore) {
                $envUpdates[$addressEnvKey] = $address;
                $envUpdates[$gasEnvKey]     = $storedPrivKey;
            }
        }

        $this->table(['Network', 'Master Receiving Address', 'Gas Station Private Key'], $rows);

        if ($shouldStore) {
            if (!file_exists($envFile)) {
                $this->warn("No .env file found at {$envFile}. Skipped saving.");
                return self::SUCCESS;
            }

            $modified = false;
            foreach ($envUpdates as $key => $value) {
                // If key already exists in .env
                if (preg_match("/^{$key}=.*/m", $envContent)) {
                    if ($force) {
                        $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $envContent);
                        $modified = true;
                    }
                } else {
                    $envContent .= "\n{$key}=\"{$value}\"";
                    $modified = true;
                }
            }

            if ($modified) {
                file_put_contents($envFile, $envContent);
                $this->info("Successfully saved master and gas wallet credentials to .env file!");
            } else {
                $this->comment("Master keys already exist in .env. Use --force to overwrite.");
            }
        }

        $this->line("\n<fg=cyan>Note:</> The generated master addresses serve as both your receiving Vault and Hot Gas Station.");
        return self::SUCCESS;
    }
}