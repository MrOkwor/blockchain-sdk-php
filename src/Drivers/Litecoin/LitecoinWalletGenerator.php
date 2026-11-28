<?php

namespace BlockchainSdk\Drivers\Litecoin;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Bech32;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\DTOs\Keypair;
use InvalidArgumentException;

class LitecoinWalletGenerator implements WalletGeneratorInterface
{
    public function generateWallet(): Keypair
    {
        do {
            $key = bin2hex(random_bytes(32));
        } while (gmp_cmp(gmp_init($key, 16), 0) <= 0 || gmp_cmp(gmp_init($key, 16), gmp_init(Secp256k1::N_HEX, 16)) >= 0);

        return new Keypair($this->privateKeyToAddress($key), '0x'.$key);
    }

    public function privateKeyToAddress(string $privateKey): string
    {
        $public = Secp256k1::privateKeyToPublicKey(self::privateKeyHex($privateKey));
        $hash = hash('ripemd160', hash('sha256', hex2bin($public['compressed']), true), true);

        return Bech32::encodeSegwit('ltc', 0, $hash);
    }

    public static function privateKeyHex(string $privateKey): string
    {
        $hex = preg_replace('/^0x/i', '', $privateKey);
        if (! preg_match('/^[0-9a-f]{64}$/iD', $hex)) {
            $payload = Base58::decodeCheck($privateKey);
            if (strlen($payload) !== 34 || ord($payload[0]) !== 176 || substr($payload, -1) !== "\x01") {
                throw new InvalidArgumentException('A Litecoin compressed WIF or 32-byte hexadecimal private key is required.');
            }
            $hex = bin2hex(substr($payload, 1, 32));
        }
        $value = gmp_init($hex, 16);
        if (gmp_cmp($value, 0) <= 0 || gmp_cmp($value, gmp_init(Secp256k1::N_HEX, 16)) >= 0) {
            throw new InvalidArgumentException('Invalid Litecoin private key.');
        }

        return strtolower($hex);
    }

    public static function exportWif(string $privateKey): string
    {
        return Base58::encodeCheck("\xb0".hex2bin(self::privateKeyHex($privateKey))."\x01");
    }
}
