<?php

namespace BlockchainSdk;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Drivers\Bitcoin\BitcoinDriver;
use BlockchainSdk\Drivers\Evm\EvmDriver;
use BlockchainSdk\Drivers\Solana\SolanaDriver;
use BlockchainSdk\Drivers\Tron\TronDriver;
use InvalidArgumentException;

class BlockchainManager
{
    private array $config;
    private array $drivers = [];

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function driver(?string $network = null): NetworkDriverInterface
    {
        $network = strtolower($network ?? $this->config['default'] ?? 'ethereum');

        if (!isset($this->drivers[$network])) {
            $this->drivers[$network] = $this->createDriver($network);
        }

        return $this->drivers[$network];
    }

    public function validateAddress(string $network, string $address): bool
    {
        try {
            return $this->driver($network)->validateAddress($address);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function getMasterWallet(string $network): ?string
    {
        return $this->config['master_wallets'][strtolower($network)] ?? null;
    }

    public function getMasterGasAddress(string $network): ?string
    {
        $network = strtolower($network);
        return $this->config['master_gas_wallets'][$network]['address'] ?? null;
    }

    public function getMasterGasKey(string $network): ?string
    {
        $network = strtolower($network);
        
        // 1. Check nested master_gas_wallets config (new format)
        if (isset($this->config['master_gas_wallets'][$network]['private_key'])) {
            return self::decryptSecret($this->config['master_gas_wallets'][$network]['private_key']);
        }

        // 2. Check flat master_gas_keys config (legacy format fallback)
        if (isset($this->config['master_gas_keys'][$network])) {
            return self::decryptSecret($this->config['master_gas_keys'][$network]);
        }

        return null;
    }

    public static function decryptSecret(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $trimmed = trim($value);

        // 1. Explicit plaintext mode
        if (str_starts_with($trimmed, 'plain:')) {
            return substr($trimmed, 6);
        }

        // 2. Explicit encrypted mode (enc:v1: or enc:) - Fail closed on corruption
        if (str_starts_with($trimmed, 'enc:v1:') || str_starts_with($trimmed, 'enc:')) {
            $ciphertext = str_starts_with($trimmed, 'enc:v1:') ? substr($trimmed, 7) : substr($trimmed, 4);
            if (class_exists(\Illuminate\Support\Facades\Crypt::class)) {
                try {
                    return \Illuminate\Support\Facades\Crypt::decryptString($ciphertext);
                } catch (\Throwable $e) {
                    throw new \RuntimeException("Failed to decrypt encrypted private key: " . $e->getMessage());
                }
            }
            throw new \RuntimeException("Cannot decrypt secret: Laravel Crypt service is not available.");
        }

        // 3. Backward-compatible fallback for legacy unprefixed keys
        if (class_exists(\Illuminate\Support\Facades\Crypt::class)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($trimmed);
            } catch (\Throwable $e) {
                // Not encrypted or already plaintext
                return $trimmed;
            }
        }

        return $trimmed;
    }

    public function getAvailableNetworks(): array
    {
        return array_keys($this->config['networks'] ?? []);
    }

    public function supportedDrivers(): array
    {
        return $this->getAvailableNetworks();
    }

    public function getSupportedTokens(string $network, bool $onlyEnabled = false): array
    {
        $network = strtolower($network);
        $tokens = $this->config['networks'][$network]['tokens'] ?? [];

        if (!$onlyEnabled) {
            return $tokens;
        }

        return array_filter($tokens, function ($token) {
            $status = $token['status'] ?? 'enabled';
            return $status === 'enabled' || $status === true;
        });
    }

    public function findToken(string $network, string $symbolOrContract, bool $onlyEnabled = false): ?array
    {
        $tokens = $this->getSupportedTokens($network, $onlyEnabled);
        foreach ($tokens as $sym => $token) {
            if (strcasecmp($sym, $symbolOrContract) === 0 || strcasecmp($token['contract'] ?? '', $symbolOrContract) === 0) {
                return array_merge(['symbol' => $sym], $token);
            }
        }
        return null;
    }

    public function isTokenEnabled(string $network, string $symbolOrContract): bool
    {
        $token = $this->findToken($network, $symbolOrContract, false);
        if (!$token) {
            return false;
        }
        $status = $token['status'] ?? 'enabled';
        return $status === 'enabled' || $status === true;
    }

    private function createDriver(string $network): NetworkDriverInterface
    {
        $networkConfig = $this->config['networks'][$network] ?? null;

        if (!$networkConfig) {
            throw new InvalidArgumentException("Blockchain network [{$network}] is not configured.");
        }

        $type = $networkConfig['type'] ?? $network;

        return match (strtolower($type)) {
            'ethereum', 'evm', 'bsc', 'polygon', 'arbitrum', 'optimism', 'base', 'avalanche', 'fantom', 'cronos', 'linea', 'scroll', 'zksync', 'celo', 'mantle' => new EvmDriver($networkConfig),
            'solana'  => new SolanaDriver($networkConfig),
            'bitcoin' => new BitcoinDriver($networkConfig),
            'tron'    => new TronDriver($networkConfig),
            default   => throw new InvalidArgumentException("Unsupported blockchain driver type [{$type}]."),
        };
    }
}