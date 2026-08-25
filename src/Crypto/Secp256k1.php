<?php

namespace BlockchainSdk\Crypto;

class Secp256k1
{
    const P_HEX  = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F';
    const N_HEX  = 'FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141';
    const GX_HEX = '79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798';
    const GY_HEX = '483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8';

    public static function pointMultiply(\GMP $k, \GMP $px, \GMP $py, \GMP $p): array
    {
        $rx = null; $ry = null;
        $qx = $px;  $qy = $py;
        $kBin = gmp_strval($k, 2);

        for ($i = strlen($kBin) - 1; $i >= 0; $i--) {
            if ($kBin[$i] === '1') {
                if ($rx === null) {
                    $rx = $qx;
                    $ry = $qy;
                } else {
                    list($rx, $ry) = self::pointAdd($rx, $ry, $qx, $qy, $p);
                }
            }
            list($qx, $qy) = self::pointDouble($qx, $qy, $p);
        }

        return [$rx, $ry];
    }

    public static function pointDouble(\GMP $x, \GMP $y, \GMP $p): array
    {
        if (gmp_cmp($y, 0) === 0) return [null, null];
        $slope = gmp_mod(
            gmp_mul(gmp_mul(3, gmp_pow($x, 2)), gmp_invert(gmp_mul(2, $y), $p)),
            $p
        );
        $rx = gmp_mod(gmp_sub(gmp_pow($slope, 2), gmp_mul(2, $x)), $p);
        if (gmp_cmp($rx, 0) < 0) $rx = gmp_add($rx, $p);
        $ry = gmp_mod(gmp_sub(gmp_mul($slope, gmp_sub($x, $rx)), $y), $p);
        if (gmp_cmp($ry, 0) < 0) $ry = gmp_add($ry, $p);
        return [$rx, $ry];
    }

    public static function pointAdd(\GMP $x1, \GMP $y1, \GMP $x2, \GMP $y2, \GMP $p): array
    {
        if (gmp_cmp($x1, $x2) === 0 && gmp_cmp($y1, $y2) === 0) return self::pointDouble($x1, $y1, $p);
        if (gmp_cmp($x1, $x2) === 0) return [null, null];
        $dx = gmp_mod(gmp_sub($x2, $x1), $p);
        if (gmp_cmp($dx, 0) < 0) $dx = gmp_add($dx, $p);
        $dy = gmp_mod(gmp_sub($y2, $y1), $p);
        if (gmp_cmp($dy, 0) < 0) $dy = gmp_add($dy, $p);
        $slope = gmp_mod(gmp_mul($dy, gmp_invert($dx, $p)), $p);
        $rx = gmp_mod(gmp_sub(gmp_sub(gmp_pow($slope, 2), $x1), $x2), $p);
        if (gmp_cmp($rx, 0) < 0) $rx = gmp_add($rx, $p);
        $ry = gmp_mod(gmp_sub(gmp_mul($slope, gmp_sub($x1, $rx)), $y1), $p);
        if (gmp_cmp($ry, 0) < 0) $ry = gmp_add($ry, $p);
        return [$rx, $ry];
    }

    public static function privateKeyToPublicKey(string $privHex): array
    {
        $p = gmp_init(self::P_HEX, 16);
        $gx = gmp_init(self::GX_HEX, 16);
        $gy = gmp_init(self::GY_HEX, 16);
        $k = gmp_init($privHex, 16);

        list($x, $y) = self::pointMultiply($k, $gx, $gy, $p);
        $xHex = str_pad(gmp_strval($x, 16), 64, '0', STR_PAD_LEFT);
        $yHex = str_pad(gmp_strval($y, 16), 64, '0', STR_PAD_LEFT);

        return [
            'x' => $xHex,
            'y' => $yHex,
            'uncompressed' => '04' . $xHex . $yHex,
            'compressed' => (gmp_testbit($y, 0) ? '03' : '02') . $xHex,
        ];
    }

    public static function signRfc6979(string $privHex, string $hashHex): array
    {
        // 1. If ext-secp256k1 is loaded in high-security custodial environments, leverage C library
        if (function_exists('secp256k1_context_create') && function_exists('secp256k1_ecdsa_sign_recoverable')) {
            try {
                $ctx = secp256k1_context_create(1); // SECP256K1_CONTEXT_SIGN
                $msg32 = hex2bin(str_pad($hashHex, 64, '0', STR_PAD_LEFT));
                $priv32 = hex2bin(str_pad($privHex, 64, '0', STR_PAD_LEFT));
                $recSig = '';
                if (secp256k1_ecdsa_sign_recoverable($ctx, $recSig, $msg32, $priv32)) {
                    $serialized = '';
                    $recId = 0;
                    if (secp256k1_ecdsa_recoverable_signature_serialize_compact($ctx, $recSig, $serialized, $recId)) {
                        return [
                            'r' => bin2hex(substr($serialized, 0, 32)),
                            's' => bin2hex(substr($serialized, 32, 32)),
                            'v' => $recId,
                        ];
                    }
                }
            } catch (\Throwable $e) {
                // Fallback to pure-PHP implementation
            }
        }

        // 2. Pure PHP portable implementation with GMP
        $n = gmp_init(self::N_HEX, 16);
        $p = gmp_init(self::P_HEX, 16);
        $gx = gmp_init(self::GX_HEX, 16);
        $gy = gmp_init(self::GY_HEX, 16);
        $d = gmp_init($privHex, 16);
        $e = gmp_init($hashHex, 16);

        $kBytes = self::generateRfc6979Nonce($privHex, $hashHex);
        $k = gmp_init(bin2hex($kBytes), 16);

        list($x1, $y1) = self::pointMultiply($k, $gx, $gy, $p);
        $r = gmp_mod($x1, $n);
        $kinv = gmp_invert($k, $n);
        $s = gmp_mod(gmp_mul($kinv, gmp_add($e, gmp_mul($r, $d))), $n);

        $halfN = gmp_div_q($n, 2);
        $v = gmp_testbit($y1, 0) ? 1 : 0;
        if (gmp_cmp($s, $halfN) > 0) {
            $s = gmp_sub($n, $s);
            $v ^= 1;
        }

        return [
            'r' => str_pad(gmp_strval($r, 16), 64, '0', STR_PAD_LEFT),
            's' => str_pad(gmp_strval($s, 16), 64, '0', STR_PAD_LEFT),
            'v' => $v,
        ];
    }

    private static function generateRfc6979Nonce(string $privHex, string $hashHex): string
    {
        $x = hex2bin(str_pad($privHex, 64, '0', STR_PAD_LEFT));
        $h1 = hex2bin(str_pad($hashHex, 64, '0', STR_PAD_LEFT));
        $v = str_repeat("\x01", 32);
        $k = str_repeat("\x00", 32);

        $k = hash_hmac('sha256', $v . "\x00" . $x . $h1, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);
        $k = hash_hmac('sha256', $v . "\x01" . $x . $h1, $k, true);
        $v = hash_hmac('sha256', $v, $k, true);

        return hash_hmac('sha256', $v, $k, true);
    }
}