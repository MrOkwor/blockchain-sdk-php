<?php

namespace BlockchainSdk\Crypto;

class Bech32
{
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

    public static function encode(string $hrp, array $data): string
    {
        $combined = array_merge($data, self::createChecksum($hrp, $data));
        $result = $hrp . '1';
        foreach ($combined as $val) {
            $result .= self::CHARSET[$val];
        }
        return $result;
    }

    public static function encodeSegwit(string $hrp, int $version, string $program): string
    {
        $data = array_merge([$version], self::convertBits(unpack('C*', $program), 8, 5, true));
        return self::encode($hrp, $data);
    }

    private static function polymod(array $values): int
    {
        $chk = 1;
        $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
        foreach ($values as $v) {
            $b = $chk >> 25;
            $chk = (($chk & 0x1ffffff) << 5) ^ $v;
            for ($i = 0; $i < 5; $i++) {
                if (($b >> $i) & 1) $chk ^= $gen[$i];
            }
        }
        return $chk;
    }

    private static function hrpExpand(string $hrp): array
    {
        $ret = [];
        for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) >> 5;
        $ret[] = 0;
        for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) & 31;
        return $ret;
    }

    private static function createChecksum(string $hrp, array $data): array
    {
        $values = array_merge(self::hrpExpand($hrp), $data, [0, 0, 0, 0, 0, 0]);
        $mod = self::polymod($values) ^ 1;
        $ret = [];
        for ($i = 0; $i < 6; $i++) {
            $ret[] = ($mod >> 5 * (5 - $i)) & 31;
        }
        return $ret;
    }

    public static function convertBits(array $data, int $fromBits, int $toBits, bool $pad = true): array
    {
        $acc = 0;
        $bits = 0;
        $ret = [];
        $maxv = (1 << $toBits) - 1;
        foreach ($data as $value) {
            $acc = ($acc << $fromBits) | $value;
            $bits += $fromBits;
            while ($bits >= $toBits) {
                $bits -= $toBits;
                $ret[] = ($acc >> $bits) & $maxv;
            }
        }
        if ($pad && $bits > 0) {
            $ret[] = ($acc << ($toBits - $bits)) & $maxv;
        }
        return $ret;
    }
}