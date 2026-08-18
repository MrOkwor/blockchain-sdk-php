<?php

namespace BlockchainSdk\Crypto;

class Keccak
{
    public static function hash(string $data): string
    {
        $rate      = 136;
        $outputLen = 32;

        $padLen = $rate - (strlen($data) % $rate);
        if ($padLen === 1) {
            $data .= "\x81";
        } else {
            $data .= "\x01" . str_repeat("\x00", $padLen - 2) . "\x80";
        }

        $state = array_fill(0, 25, 0);

        $numBlocks = strlen($data) / $rate;
        for ($b = 0; $b < $numBlocks; $b++) {
            $block = substr($data, $b * $rate, $rate);
            for ($i = 0; $i < $rate / 8; $i++) {
                $state[$i] ^= unpack('P', substr($block, $i * 8, 8))[1];
            }
            $state = self::keccakF1600($state);
        }

        $output = '';
        for ($i = 0; $i < $outputLen / 8; $i++) {
            $output .= pack('P', $state[$i]);
        }

        return bin2hex($output);
    }

    private static function keccakF1600(array $state): array
    {
        static $RC = null;
        if ($RC === null) {
            $M  = PHP_INT_MIN;
            $RC = [
                0x0000000000000001,
                0x0000000000008082,
                $M | 0x000000000000808A,
                $M | 0x0000000080008000,
                0x000000000000808B,
                0x0000000080000001,
                $M | 0x0000000080008081,
                $M | 0x0000000000008009,
                0x000000000000008A,
                0x0000000000000088,
                0x0000000080008009,
                0x000000008000000A,
                0x000000008000808B,
                $M | 0x000000000000008B,
                $M | 0x0000000000008089,
                $M | 0x0000000000008003,
                $M | 0x0000000000008002,
                $M | 0x0000000000000080,
                0x000000000000800A,
                $M | 0x000000008000000A,
                $M | 0x0000000080008081,
                $M | 0x0000000000008080,
                0x0000000080000001,
                $M | 0x0000000080008008,
            ];
        }

        static $ROT = [
            [ 0, 36,  3, 41, 18],
            [ 1, 44, 10, 45,  2],
            [62,  6, 43, 15, 61],
            [28, 55, 25, 21, 56],
            [27, 20, 39,  8, 14],
        ];

        for ($round = 0; $round < 24; $round++) {
            $C = [
                $state[0]  ^ $state[5]  ^ $state[10] ^ $state[15] ^ $state[20],
                $state[1]  ^ $state[6]  ^ $state[11] ^ $state[16] ^ $state[21],
                $state[2]  ^ $state[7]  ^ $state[12] ^ $state[17] ^ $state[22],
                $state[3]  ^ $state[8]  ^ $state[13] ^ $state[18] ^ $state[23],
                $state[4]  ^ $state[9]  ^ $state[14] ^ $state[19] ^ $state[24],
            ];
            $D = [
                $C[4] ^ self::rotl64($C[1], 1),
                $C[0] ^ self::rotl64($C[2], 1),
                $C[1] ^ self::rotl64($C[3], 1),
                $C[2] ^ self::rotl64($C[4], 1),
                $C[3] ^ self::rotl64($C[0], 1),
            ];
            for ($i = 0; $i < 25; $i++) {
                $state[$i] ^= $D[$i % 5];
            }

            $B = array_fill(0, 25, 0);
            for ($x = 0; $x < 5; $x++) {
                for ($y = 0; $y < 5; $y++) {
                    $B[$y + 5 * ((2 * $x + 3 * $y) % 5)] =
                        self::rotl64($state[$x + 5 * $y], $ROT[$x][$y]);
                }
            }

            for ($y = 0; $y < 5; $y++) {
                $o = $y * 5;
                $state[$o + 0] = $B[$o + 0] ^ ((~$B[$o + 1]) & $B[$o + 2]);
                $state[$o + 1] = $B[$o + 1] ^ ((~$B[$o + 2]) & $B[$o + 3]);
                $state[$o + 2] = $B[$o + 2] ^ ((~$B[$o + 3]) & $B[$o + 4]);
                $state[$o + 3] = $B[$o + 3] ^ ((~$B[$o + 4]) & $B[$o + 0]);
                $state[$o + 4] = $B[$o + 4] ^ ((~$B[$o + 0]) & $B[$o + 1]);
            }

            $state[0] ^= $RC[$round];
        }

        return $state;
    }

    private static function rotl64(int $x, int $n): int
    {
        if ($n === 0) {
            return $x;
        }
        return ($x << $n) | (($x >> (64 - $n)) & ~((-1) << $n));
    }
}