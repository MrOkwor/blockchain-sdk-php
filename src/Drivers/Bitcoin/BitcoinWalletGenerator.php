<?php

namespace BlockchainSdk\Drivers\Bitcoin;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Bech32;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\DTOs\Keypair;

class BitcoinWalletGenerator implements WalletGeneratorInterface
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
        $sha = hash('sha256', hex2bin($pub['compressed']), true);
        $ripemd = hash('ripemd160', $sha, true);

        return Bech32::encodeSegwit('bc', 0, $ripemd);
    }
}