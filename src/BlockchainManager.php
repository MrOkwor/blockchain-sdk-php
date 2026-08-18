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