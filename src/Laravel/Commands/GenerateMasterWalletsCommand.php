<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Laravel\Facades\Blockchain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

class GenerateMasterWalletsCommand extends Command
{
    protected $signature = 'blockchainsdk:generate-master-wallets 
                            {network? : Optional target blockchain network (ethereum, bsc, polygon, solana, tron, bitcoin, etc.)}
                            {--network= : Target blockchain network}
                            {--no-encrypt : Store raw plaintext private keys instead of encrypting}
                            {--no-store : Output credentials to console without writing to .env}
                            {--force : Overwrite existing master and gas wallet keys in .env}';

    protected $description = 'Generate master cold vault receiving addresses and hot gas station credentials, with separate .env blocks and automated encryption';

    public function handle(): int
    {
        $targetNetwork = $this->argument('network') ?? $this->option('network');
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
        $this->line("Mode: <fg=yellow>" . ($shouldEncrypt ? 'Encrypted (AES-256 with enc:v1: prefix)' : 'Plaintext (plain: prefix)') . "</>");
        $this->line("Storage: <fg=yellow>" . ($shouldStore ? '.env file' : 'Console output only') . "</>\n");

        $masterRows = [];
        $gasRows = [];

        $masterEnvUpdates = [];
        $gasEnvUpdates = [];

        foreach ($networks as $network) {
            $keypair = Blockchain::driver($network)->generateWallet();
            $address = $keypair->address;
            $rawPrivKey = $keypair->privateKey;

            $storedPrivKey = $shouldEncrypt ? ('enc:v1:' . Crypt::encryptString($rawPrivKey)) : ('plain:' . $rawPrivKey);

            // 1. Master Cold Vault block
            $masterKey = 'BLOCKCHAIN_MASTER_' . strtoupper($network);
            $masterRows[] = [
                ucfirst($network),
                $address,
            ];
            $masterEnvUpdates[$masterKey] = $address;

            // 2. Hot Gas Wallet / Key block
            $gasAddressKey = 'BLOCKCHAIN_GAS_ADDRESS_' . strtoupper($network);
            $gasKey        = 'BLOCKCHAIN_GAS_KEY_' . strtoupper($network);

            $gasRows[] = [
                ucfirst($network),
                $address,
                $shouldEncrypt ? ('enc:v1:' . substr($storedPrivKey, 7, 18) . '...[ENCRYPTED]') : (substr($rawPrivKey, 0, 10) . '...'),
            ];

            $gasEnvUpdates[$gasAddressKey] = $address;
            $gasEnvUpdates[$gasKey]        = $storedPrivKey;
        }

        $this->comment("--- Master Cold Vault Receiving Addresses ---");
        $this->table(['Network', 'Master Cold Vault Address'], $masterRows);

        $this->comment("--- Hot Gas Station Wallets & Private Keys ---");
        $this->table(['Network', 'Gas Station Address', 'Gas Private Key'], $gasRows);

        if ($shouldStore) {
            $envFile = base_path('.env');
            if (!file_exists($envFile)) {
                $this->warn("No .env file found at {$envFile}. Skipped saving.");
                return self::SUCCESS;
            }

            $envContent = file_get_contents($envFile);

            $masterBlock = "\n# ==============================================================================\n" .
                           "# BLOCKCHAIN SDK: MASTER COLD VAULT RECEIVING WALLETS\n" .
                           "# Destination addresses where customer sub-wallet funds are swept and stored.\n" .
                           "# ==============================================================================";

            $gasBlock = "\n# ==============================================================================\n" .
                        "# BLOCKCHAIN SDK: HOT GAS STATION WALLETS & PRIVATE KEYS\n" .
                        "# Wallets used solely to sponsor and fuel gas fees into sub-wallets before sweeps.\n" .
                        "# ==============================================================================";

            $modified = false;

            // Append Master Block Header if missing
            if (!str_contains($envContent, 'BLOCKCHAIN SDK: MASTER COLD VAULT RECEIVING WALLETS')) {
                $envContent .= $masterBlock;
            }

            foreach ($masterEnvUpdates as $key => $value) {
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

            // Append Gas Block Header if missing
            if (!str_contains($envContent, 'BLOCKCHAIN SDK: HOT GAS STATION WALLETS & PRIVATE KEYS')) {
                $envContent .= $gasBlock;
            }

            foreach ($gasEnvUpdates as $key => $value) {
                if (preg_match("/^{$key}=\"?(.*?)\"?\s*$/m", $envContent, $matches)) {
                    $existingVal = $matches[1] ?? '';
                    if ($force) {
                        $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$value}\"", $envContent);
                        $modified = true;
                    } elseif (!empty($existingVal) && !str_starts_with($existingVal, 'enc:') && !str_starts_with($existingVal, 'plain:')) {
                        // Normalize legacy unprefixed key: test if decryptable
                        try {
                            Crypt::decryptString($existingVal);
                            $normalizedVal = "enc:v1:{$existingVal}";
                        } catch (\Throwable $e) {
                            $normalizedVal = "plain:{$existingVal}";
                        }
                        $envContent = preg_replace("/^{$key}=.*/m", "{$key}=\"{$normalizedVal}\"", $envContent);
                        $modified = true;
                        $this->line("Prefixed existing key {$key} with " . (str_starts_with($normalizedVal, 'enc:') ? '<fg=green>enc:v1:</>' : '<fg=yellow>plain:</>'));
                    }
                } else {
                    $envContent .= "\n{$key}=\"{$value}\"";
                    $modified = true;
                }
            }

            if ($modified) {
                file_put_contents($envFile, $envContent);
                $this->info("Successfully saved separate Master Vault and Hot Gas Station blocks to .env file!");
            } else {
                $this->comment("Keys already exist in .env. Use --force to overwrite.");
            }
        }

        $this->line("\n<fg=cyan>Tip:</> You can safely edit the Master Vault address in .env anytime (e.g. pointing to a Hardware Ledger or Gnosis Safe) without affecting the Hot Gas Station wallet.");
        return self::SUCCESS;
    }
}