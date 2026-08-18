<?php

namespace BlockchainSdk\Drivers\Evm;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Keccak;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\DTOs\Keypair;

class EvmWalletGenerator implements WalletGeneratorInterface
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
        $pubBytes = hex2bin(substr($pub['uncompressed'], 2)); // remove '04'
        $hashHex = Keccak::hash($pubBytes);
        $rawAddr = str_pad(substr($hashHex, 24), 40, '0', STR_PAD_LEFT); // last 20 bytes = 40 hex chars

        return self::toChecksumAddress('0x' . $rawAddr);
    }

    public static function toChecksumAddress(string $address): string
    {
        $addr = str_pad(strtolower(ltrim($address, '0x')), 40, '0', STR_PAD_LEFT);
        $hash = Keccak::hash($addr);
        $checksum = '0x';

        for ($i = 0; $i < 40; $i++) {
            $char = $addr[$i] ?? '0';
            if (ctype_alpha($char)) {
                $checksum .= (hexdec($hash[$i] ?? '0') >= 8) ? strtoupper($char) : $char;
            } else {
                $checksum .= $char;
            }
        }

        return $checksum;
    }
}