<?php

namespace BlockchainSdk\Drivers\Solana;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\DTOs\Keypair;

class SolanaWalletGenerator implements WalletGeneratorInterface
{
    public function generateWallet(): Keypair
    {
        $seed = random_bytes(32);
        $keypair = sodium_crypto_sign_seed_keypair($seed);
        $pubKey = sodium_crypto_sign_publickey($keypair);
        $privKey = sodium_crypto_sign_secretkey($keypair);

        $address = Base58::encode($pubKey);
        $privateKeyBase58 = Base58::encode($privKey);

        return new Keypair(
            address: $address,
            privateKey: $privateKeyBase58,
            publicKey: $address
        );
    }

    public function privateKeyToAddress(string $privateKey): string
    {
        $privKeyBin = Base58::decode($privateKey);
        if (strlen($privKeyBin) === 64) {
            $pubKeyBin = substr($privKeyBin, 32);
        } else {
            $keypair = sodium_crypto_sign_seed_keypair($privKeyBin);
            $pubKeyBin = sodium_crypto_sign_publickey($keypair);
        }
        return Base58::encode($pubKeyBin);
    }
}