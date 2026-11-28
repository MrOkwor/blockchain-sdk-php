<?php

namespace BlockchainSdk\Laravel\Commands;

use BlockchainSdk\Crypto\MasterWalletDerivation;
use BlockchainSdk\Laravel\Facades\Blockchain;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class GenerateMasterWalletsCommand extends Command
{
    private const SEED_KEY = 'BLOCKCHAIN_MASTER_SEED_PHRASE';

    protected $signature = 'blockchainsdk:generate-master-wallets 
                            {network? : Optional target blockchain network (ethereum, bsc, polygon, solana, tron, bitcoin, etc.)}
                            {--network= : Target blockchain network}
                            {--no-encrypt : Store raw plaintext private keys instead of encrypting}
                            {--no-store : Output credentials to console without writing to .env}
                            {--force : Overwrite existing master and gas wallet keys in .env (gas credentials only in seed mode)}
                            {--seed-phrase : Derive supported master and gas wallets from one BIP-39 recovery phrase}
                            {--show-seed-phrase : Display the recovery phrase in seed mode}';

    protected $description = 'Generate master cold vault receiving addresses and hot gas station credentials, with separate .env blocks and automated encryption';

    public function handle(): int
    {
        $targetNetwork = $this->argument('network') ?? $this->option('network');
        $shouldEncrypt = ! $this->option('no-encrypt');
        $shouldStore = ! $this->option('no-store');
        $force = (bool) $this->option('force');

        $allNetworks = Blockchain::getAvailableNetworks();

        if ($targetNetwork) {
            $targetNetwork = strtolower($targetNetwork);
            if (! in_array($targetNetwork, $allNetworks)) {
                $this->error("Unsupported network [{$targetNetwork}]. Available networks: ".implode(', ', $allNetworks));

                return self::FAILURE;
            }
            $networks = [$targetNetwork];
        } else {
            $networks = $allNetworks;
        }

        if ($this->option('seed-phrase')) {
            return $this->generateFromSeed($networks, $shouldStore, $shouldEncrypt, $force);
        }
        if ($this->option('show-seed-phrase')) {
            $this->error('--show-seed-phrase requires --seed-phrase.');

            return self::FAILURE;
        }

        $this->info('=== Blockchain SDK Master & Gas Wallet Generator ===');
        $this->line('Mode: <fg=yellow>'.($shouldEncrypt ? 'Encrypted (AES-256 with enc:v1: prefix)' : 'Plaintext (plain: prefix)').'</>');
        $this->line('Storage: <fg=yellow>'.($shouldStore ? '.env file' : 'Console output only')."</>\n");

        $masterRows = [];
        $gasRows = [];

        $masterEnvUpdates = [];
        $gasEnvUpdates = [];

        foreach ($networks as $network) {
            $keypair = Blockchain::driver($network)->generateWallet();
            $address = $keypair->address;
            $rawPrivKey = $keypair->privateKey;

            $storedPrivKey = $shouldEncrypt ? ('enc:v1:'.Crypt::encryptString($rawPrivKey)) : ('plain:'.$rawPrivKey);

            // 1. Master Cold Vault block
            $masterKey = 'BLOCKCHAIN_MASTER_'.strtoupper($network);
            $masterRows[] = [
                ucfirst($network),
                $address,
            ];
            $masterEnvUpdates[$masterKey] = $address;

            // 2. Hot Gas Wallet / Key block
            $gasAddressKey = 'BLOCKCHAIN_GAS_ADDRESS_'.strtoupper($network);
            $gasKey = 'BLOCKCHAIN_GAS_KEY_'.strtoupper($network);

            $gasRows[] = [
                ucfirst($network),
                $address,
                $shouldEncrypt ? ('enc:v1:'.substr($storedPrivKey, 7, 18).'...[ENCRYPTED]') : (substr($rawPrivKey, 0, 10).'...'),
            ];

            $gasEnvUpdates[$gasAddressKey] = $address;
            $gasEnvUpdates[$gasKey] = $storedPrivKey;
        }

        $this->comment('--- Master Cold Vault Receiving Addresses ---');
        $this->table(['Network', 'Master Cold Vault Address'], $masterRows);

        $this->comment('--- Hot Gas Station Wallets & Private Keys ---');
        $this->table(['Network', 'Gas Station Address', 'Gas Private Key'], $gasRows);

        if ($shouldStore) {
            $envFile = $this->laravel->environmentFilePath();
            if (! is_file($envFile)) {
                $this->warn("No .env file found at {$envFile}. Skipped saving.");

                return self::SUCCESS;
            }

            $envContent = file_get_contents($envFile);
            if ($envContent === false) {
                $this->error('The environment file could not be read.');

                return self::FAILURE;
            }

            $updates = [];
            foreach ($masterEnvUpdates + $gasEnvUpdates as $key => $value) {
                if ($force || ! preg_match($this->envEntryPattern($key), $envContent, $matches)) {
                    $updates[$key] = $value;

                    continue;
                }

                if (! str_starts_with($key, 'BLOCKCHAIN_GAS_KEY_')) {
                    continue;
                }

                $existingValue = Dotenv::parse($matches[0])[$key] ?? '';
                if ($existingValue === '' || str_starts_with($existingValue, 'enc:') || str_starts_with($existingValue, 'plain:')) {
                    continue;
                }

                try {
                    Crypt::decryptString($existingValue);
                    $updates[$key] = 'enc:v1:'.$existingValue;
                } catch (\Throwable) {
                    $updates[$key] = 'plain:'.$existingValue;
                }
                $this->line("Prefixed existing private key {$key}.");
            }

            if ($updates !== []) {
                (new Filesystem)->replace($envFile, $this->formatWalletEnv($envContent, $updates), 0600);
                $this->info('Successfully saved separate Master Vault and Hot Gas Station blocks to .env file!');
            } else {
                $this->comment('Keys already exist in .env. Use --force to overwrite.');
            }
        }

        $this->line("\n<fg=cyan>Tip:</> You can safely edit the Master Vault address in .env anytime (e.g. pointing to a Hardware Ledger or Gnosis Safe) without affecting the Hot Gas Station wallet.");

        return self::SUCCESS;
    }

    private function generateFromSeed(array $networks, bool $store, bool $encryptKeys, bool $force): int
    {
        $envPath = $this->laravel->environmentFilePath();
        if ($store && ! is_file($envPath)) {
            $this->error('The Laravel environment file is missing. Use --no-store to generate an offline backup.');

            return self::FAILURE;
        }

        $lock = null;
        if ($store) {
            $lockPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'blockchainsdk-wallets-'.hash('sha256', realpath($envPath) ?: $envPath).'.lock';
            $lock = fopen($lockPath, 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                if (is_resource($lock)) {
                    fclose($lock);
                }
                $this->error('Could not lock the wallet configuration. Retry once the other generation has finished.');

                return self::FAILURE;
            }
        }

        try {
            $env = is_file($envPath) ? file_get_contents($envPath) : '';
            if ($env === false) {
                throw new RuntimeException('The environment file could not be read.');
            }
            $values = Dotenv::parse($env);
            $storedPhrase = $values[self::SEED_KEY] ?? '';
            $mnemonic = null;
            if ($storedPhrase !== '') {
                if (! str_starts_with($storedPhrase, 'enc:v1:')) {
                    throw new RuntimeException('The stored recovery phrase is not encrypted.');
                }

                $mnemonic = Crypt::decryptString(substr($storedPhrase, 7));
            }

            $derivation = new MasterWalletDerivation;
            $seed = $mnemonic === null ? null : $derivation->seedFromMnemonic($mnemonic);
            $updates = [];
            $rows = [];
            foreach ($networks as $network) {
                $suffix = strtoupper($network);
                $masterKey = 'BLOCKCHAIN_MASTER_'.$suffix;
                $gasAddressKey = 'BLOCKCHAIN_GAS_ADDRESS_'.$suffix;
                $gasKey = 'BLOCKCHAIN_GAS_KEY_'.$suffix;
                $masterOverride = ($values[$masterKey] ?? '') !== '' || (bool) Blockchain::getMasterWallet($network);
                $hasGasWallet = ($values[$gasAddressKey] ?? '') !== '' || ($values[$gasKey] ?? '') !== ''
                    || (bool) Blockchain::getMasterGasAddress($network)
                    || (bool) config('blockchainsdk.master_gas_wallets.'.$network.'.private_key')
                    || (bool) config('blockchainsdk.master_gas_keys.'.$network);

                if ($store && $hasGasWallet && ! $force) {
                    $this->line("Skipped {$network}: existing gas credentials preserved.");

                    continue;
                }

                $mnemonic ??= $derivation->generateMnemonic();
                $seed ??= $derivation->seedFromMnemonic($mnemonic);
                $wallet = $derivation->deriveWallet($seed, Blockchain::driver($network));
                $rows[] = [$network, $wallet->address];
                if (! $store) {
                    continue;
                }
                if (! $masterOverride) {
                    $updates[$masterKey] = $wallet->address;
                }
                $updates[$gasAddressKey] = $wallet->address;
                $updates[$gasKey] = $encryptKeys ? 'enc:v1:'.Crypt::encryptString($wallet->privateKey) : 'plain:'.$wallet->privateKey;
            }

            if ($updates !== []) {
                if ($storedPhrase === '') {
                    $updates[self::SEED_KEY] = 'enc:v1:'.Crypt::encryptString($mnemonic);
                }
                (new Filesystem)->replace($envPath, $this->formatWalletEnv($env, $updates), 0600);
                $this->info('Saved derived wallet credentials and encrypted recovery phrase.');
            }

            if ($rows !== []) {
                $this->table(['Network', 'Derived wallet address'], $rows);
            }
            if ($mnemonic !== null && ($this->option('show-seed-phrase') || ! $store)) {
                $this->line($mnemonic);
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Seed wallet generation failed. Verify the environment file, Laravel APP_KEY, and wallet configuration.');

            return self::FAILURE;
        } finally {
            if (is_resource($lock)) {
                fclose($lock);
            }
        }
    }

    private function envEntryPattern(string $key): string
    {
        return '/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'[ \t]*=[^\r\n]*/m';
    }

    /** @param array<string, string> $updates */
    private function formatWalletEnv(#[\SensitiveParameter] string $env, #[\SensitiveParameter] array $updates): string
    {
        $newline = str_contains($env, "\r\n") ? "\r\n" : "\n";
        $sections = [
            'master' => ['MASTER COLD VAULT RECEIVING WALLETS', 'Destination addresses where customer sub-wallet funds are swept and stored.', 'BLOCKCHAIN_MASTER_(?!SEED_PHRASE\b)[A-Z0-9_]+'],
            'gas' => ['HOT GAS STATION WALLETS & PRIVATE KEYS', 'Wallets used solely to sponsor and fuel gas fees into sub-wallets before sweeps.', 'BLOCKCHAIN_GAS_(?:ADDRESS|KEY)_[A-Z0-9_]+'],
            'seed' => ['WALLET RECOVERY PHRASE', 'Encrypted recovery phrase for derived master and gas wallets.', self::SEED_KEY],
        ];

        $pending = [];
        foreach ($updates as $key => $value) {
            $entry = $key.'="'.$value.'"';
            $pattern = $this->envEntryPattern($key);
            if (preg_match($pattern, $env)) {
                $env = preg_replace_callback($pattern, static fn (): string => $entry, $env);

                continue;
            }

            $section = match (true) {
                $key === self::SEED_KEY => 'seed',
                str_starts_with($key, 'BLOCKCHAIN_GAS_') => 'gas',
                default => 'master',
            };
            $pending[$section][] = $entry;
        }

        $divider = '# ==============================================================================';
        foreach ($sections as $section => [$title, $description, $keys]) {
            if (! isset($pending[$section])) {
                continue;
            }

            $heading = '# BLOCKCHAIN SDK: '.$title;
            $entries = implode($newline, $pending[$section]);
            $pattern = '/^'.preg_quote($heading, '/').'[ \t]*\r?\n(?:^#[^\r\n]*\r?\n)*?^# ={5,}[ \t]*'
                .'(?:\r?\n[ \t]*(?:export[ \t]+)?'.$keys.'[ \t]*=[^\r\n]*)*/m';
            if (preg_match($pattern, $env, $matches, PREG_OFFSET_CAPTURE)) {
                [$block, $offset] = $matches[0];
                $env = substr_replace($env, $newline.$entries, $offset + strlen($block), 0);
                if (! str_ends_with($env, "\n")) {
                    $env .= $newline;
                }

                continue;
            }

            $env = rtrim($env, "\r\n");
            if ($env !== '') {
                $env .= $newline.$newline;
            }
            $env .= implode($newline, [$divider, $heading, '# '.$description, $divider, $entries]).$newline;
        }

        return $env;
    }
}
