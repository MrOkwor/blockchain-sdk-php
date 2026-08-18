<?php

namespace BlockchainSdk\Drivers\Tron;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Keccak;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\DTOs\Keypair;

class TronWalletGenerator implements WalletGeneratorInterface
{
    public function generateWallet(): Keypair
    {
        $n = gmp_init(Secp256k1::N_HEX, 16);
        do {
            $privBytes = random_bytes(32);
            $privHex   = bin2hex($privBytes);
            $privKey   = gmp_init($privHex, 16);
        } while (gmp_cmp($privKey, 1) < 0 || gmp_cmp($privKey, $n) >= 0);

        $address = $this->privateKeyToAddress($privHex);

        return new Keypair(
            address: $address,
            privateKey: '0x' . $privHex
        );
    }

    public function privateKeyToAddress(string $privateKey): string
    {
        $privHex = ltrim($privateKey, '0x');
        $pub = Secp256k1::privateKeyToPublicKey($privHex);
        $pubBytes = hex2bin(substr($pub['uncompressed'], 2));
        $hashHex = Keccak::hash($pubBytes);
        $rawAddr = '41' . substr($hashHex, 24); // 41 prefix for TRON

        return Base58::encodeCheck(hex2bin($rawAddr));
    }
}