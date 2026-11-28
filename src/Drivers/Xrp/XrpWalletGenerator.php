<?php

namespace BlockchainSdk\Drivers\Xrp;

use BlockchainSdk\Contracts\WalletGeneratorInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Secp256k1;
use BlockchainSdk\DTOs\Keypair;
use InvalidArgumentException;

class XrpWalletGenerator implements WalletGeneratorInterface
{
    private const BITCOIN_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const XRP_ALPHABET = 'rpshnaf39wBUDNEGHJKLM4PQRST7VWXYZ2bcdeCg65jkm8oFqi1tuvAxyz';

    public function generateWallet(): Keypair
    {
        do {
            $key = bin2hex(random_bytes(32));
        } while (gmp_cmp(gmp_init($key, 16), 0) <= 0 || gmp_cmp(gmp_init($key, 16), gmp_init(Secp256k1::N_HEX, 16)) >= 0);
        $public = Secp256k1::privateKeyToPublicKey($key)['compressed'];

        return new Keypair($this->privateKeyToAddress($key), '0x'.$key, $public);
    }

    public static function privateKeyHex(string $key): string
    {
        $key = preg_replace('/^0x/i', '', $key);
        if (! preg_match('/^[0-9a-f]{64}$/iD', $key) || gmp_cmp(gmp_init($key, 16), 0) <= 0
            || gmp_cmp(gmp_init($key, 16), gmp_init(Secp256k1::N_HEX, 16)) >= 0) {
            throw new InvalidArgumentException('A valid 32-byte XRP secp256k1 hexadecimal private key is required.');
        }

        return strtolower($key);
    }

    public function privateKeyToAddress(string $privateKey): string
    {
        $public = Secp256k1::privateKeyToPublicKey(self::privateKeyHex($privateKey));
        $id = hash('ripemd160', hash('sha256', hex2bin($public['compressed']), true), true);

        return strtr(Base58::encodeCheck("\x00".$id), self::BITCOIN_ALPHABET, self::XRP_ALPHABET);
    }

    public static function accountId(string $address): string
    {
        $payload = Base58::decodeCheck(strtr($address, self::XRP_ALPHABET, self::BITCOIN_ALPHABET));
        if (strlen($payload) !== 21 || $payload[0] !== "\x00") {
            throw new InvalidArgumentException('Invalid XRP classic address.');
        }

        return substr($payload, 1);
    }
}
