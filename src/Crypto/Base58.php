<?php

namespace BlockchainSdk\Crypto;

class Base58
{
    private static string $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    public static function encode(string $data): string
    {
        if (strlen($data) === 0) return '';
        $int = gmp_init(bin2hex($data), 16);
        $base = gmp_init(58, 10);
        $encoded = '';

        while (gmp_cmp($int, 0) > 0) {
            list($int, $rem) = gmp_div_qr($int, $base);
            $encoded = self::$alphabet[gmp_intval($rem)] . $encoded;
        }

        // Leading zeros
        for ($i = 0; $i < strlen($data) && $data[$i] === "\x00"; $i++) {
            $encoded = '1' . $encoded;
        }

        return $encoded;
    }

    public static function decode(string $base58): string
    {
        if (strlen($base58) === 0) return '';
        $int = gmp_init(0, 10);
        $base = gmp_init(58, 10);

        for ($i = 0; $i < strlen($base58); $i++) {
            $pos = strpos(self::$alphabet, $base58[$i]);
            if ($pos === false) throw new \InvalidArgumentException("Invalid Base58 character: {$base58[$i]}");
            $int = gmp_add(gmp_mul($int, $base), $pos);
        }

        $hex = gmp_strval($int, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        $bin = hex2bin($hex);

        for ($i = 0; $i < strlen($base58) && $base58[$i] === '1'; $i++) {
            $bin = "\x00" . $bin;
        }

        return $bin;
    }

    public static function encodeCheck(string $data): string
    {
        $checksum = substr(hash('sha256', hash('sha256', $data, true), true), 0, 4);
        return self::encode($data . $checksum);
    }

    public static function decodeCheck(string $base58): string
    {
        $decoded = self::decode($base58);
        $payload = substr($decoded, 0, -4);
        $checksum = substr($decoded, -4);
        $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);

        if ($checksum !== $expected) {
            throw new \InvalidArgumentException("Invalid Base58Check checksum");
        }

        return $payload;
    }
}