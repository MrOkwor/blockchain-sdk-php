<?php

namespace BlockchainSdk\Crypto;

use BlockchainSdk\Contracts\NetworkDriverInterface;
use BlockchainSdk\Drivers\Bitcoin\BitcoinDriver;
use BlockchainSdk\Drivers\Bitcoin\BitcoinWalletGenerator;
use BlockchainSdk\Drivers\Evm\EvmDriver;
use BlockchainSdk\Drivers\Evm\EvmWalletGenerator;
use BlockchainSdk\Drivers\Litecoin\LitecoinDriver;
use BlockchainSdk\Drivers\Litecoin\LitecoinWalletGenerator;
use BlockchainSdk\Drivers\Solana\SolanaDriver;
use BlockchainSdk\Drivers\Solana\SolanaWalletGenerator;
use BlockchainSdk\Drivers\Tron\TronDriver;
use BlockchainSdk\Drivers\Tron\TronWalletGenerator;
use BlockchainSdk\Drivers\Xrp\XrpDriver;
use BlockchainSdk\Drivers\Xrp\XrpWalletGenerator;
use BlockchainSdk\DTOs\Keypair;
use InvalidArgumentException;
use RuntimeException;

class MasterWalletDerivation
{
    private const HARDENED = 0x80000000;

    public function generateMnemonic(): string
    {
        $entropy = random_bytes(32);
        $bits = $this->bits($entropy).$this->bits(hash('sha256', $entropy, true)[0]);
        $wordList = $this->wordList();

        return implode(' ', array_map(static fn (string $index): string => $wordList[bindec($index)], str_split($bits, 11)));
    }

    public function seedFromMnemonic(#[\SensitiveParameter] string $mnemonic): string
    {
        $words = explode(' ', trim($mnemonic));
        if (! in_array(count($words), [12, 15, 18, 21, 24], true)) {
            throw new InvalidArgumentException('Invalid BIP-39 recovery phrase length.');
        }

        $indices = array_flip($this->wordList());
        $bits = '';
        foreach ($words as $word) {
            if (! isset($indices[$word])) {
                throw new InvalidArgumentException('Recovery phrase contains an unknown BIP-39 English word.');
            }

            $bits .= str_pad(decbin($indices[$word]), 11, '0', STR_PAD_LEFT);
        }

        $checksumSize = intdiv(strlen($bits), 33);
        $entropyBits = substr($bits, 0, -$checksumSize);
        $entropy = implode('', array_map(static fn (string $byte): string => chr(bindec($byte)), str_split($entropyBits, 8)));
        $checksum = substr($this->bits(hash('sha256', $entropy, true)[0]), 0, $checksumSize);
        if (! hash_equals($checksum, substr($bits, -$checksumSize))) {
            throw new InvalidArgumentException('Invalid BIP-39 recovery phrase checksum.');
        }

        return hash_pbkdf2('sha512', implode(' ', $words), 'mnemonic', 2048, 64, true);
    }

    public function deriveWallet(#[\SensitiveParameter] string $seed, NetworkDriverInterface $driver): Keypair
    {
        [$purpose, $coin, $generator] = match (true) {
            $driver instanceof EvmDriver => [44, 60, new EvmWalletGenerator],
            $driver instanceof BitcoinDriver => [84, 0, new BitcoinWalletGenerator],
            $driver instanceof LitecoinDriver => [84, 2, new LitecoinWalletGenerator],
            $driver instanceof TronDriver => [44, 195, new TronWalletGenerator],
            $driver instanceof XrpDriver => [44, 144, new XrpWalletGenerator],
            $driver instanceof SolanaDriver => [44, 501, new SolanaWalletGenerator],
            default => throw new InvalidArgumentException('The network driver does not support recovery phrase generation.'),
        };

        $path = [$purpose | self::HARDENED, $coin | self::HARDENED, self::HARDENED];
        if ($driver instanceof SolanaDriver) {
            $node = hash_hmac('sha512', $seed, 'ed25519 seed', true);
            foreach ([...$path, self::HARDENED] as $index) {
                $node = hash_hmac('sha512', "\0".substr($node, 0, 32).pack('N', $index), substr($node, 32), true);
            }

            $key = Base58::encode(sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair(substr($node, 0, 32))));
        } else {
            $key = '0x'.bin2hex($this->deriveSecp256k1($seed, [...$path, 0, 0]));
        }

        return new Keypair($generator->privateKeyToAddress($key), $key);
    }

    private function deriveSecp256k1(#[\SensitiveParameter] string $seed, array $path): string
    {
        $node = hash_hmac('sha512', $seed, 'Bitcoin seed', true);
        $key = substr($node, 0, 32);
        $chainCode = substr($node, 32);
        $order = gmp_init(Secp256k1::N_HEX, 16);
        $scalar = gmp_init(bin2hex($key), 16);
        if (gmp_cmp($scalar, 0) === 0 || gmp_cmp($scalar, $order) >= 0) {
            throw new RuntimeException('The recovery phrase produced an invalid BIP-32 master key.');
        }

        foreach ($path as $index) {
            $parent = ($index & self::HARDENED) !== 0
                ? "\0".$key
                : hex2bin(Secp256k1::privateKeyToPublicKey(bin2hex($key))['compressed']);
            $node = hash_hmac('sha512', $parent.pack('N', $index), $chainCode, true);
            $offset = gmp_init(bin2hex(substr($node, 0, 32)), 16);
            $scalar = gmp_mod(gmp_add($scalar, $offset), $order);
            if (gmp_cmp($offset, $order) >= 0 || gmp_cmp($scalar, 0) === 0) {
                throw new RuntimeException('The recovery phrase produced an invalid key at the configured BIP-32 path.');
            }

            $key = hex2bin(str_pad(gmp_strval($scalar, 16), 64, '0', STR_PAD_LEFT));
            $chainCode = substr($node, 32);
        }

        return $key;
    }

    private function bits(string $bytes): string
    {
        return implode('', array_map(static fn (int $byte): string => str_pad(decbin($byte), 8, '0', STR_PAD_LEFT), unpack('C*', $bytes)));
    }

    private function wordList(): array
    {
        // Official BIP-39 English word list, in index order.
        $words = file(__DIR__.'/bip39-english.txt', FILE_IGNORE_NEW_LINES);
        if ($words === false || count($words) !== 2048) {
            throw new RuntimeException('The BIP-39 English word list is unavailable.');
        }

        return $words;
    }
}
