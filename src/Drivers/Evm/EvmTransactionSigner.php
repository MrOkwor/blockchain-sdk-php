<?php

namespace BlockchainSdk\Drivers\Evm;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Keccak;
use BlockchainSdk\Crypto\Secp256k1;

class EvmTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $privHex = ltrim($params['private_key'], '0x');
        $to = str_pad(strtolower(ltrim($params['to'], '0x')), 40, '0', STR_PAD_LEFT);
        $nonce = (int)$params['nonce'];
        $gasPriceWei = (string)$params['gas_price'];
        $gasLimit = (int)$params['gas_limit'];
        $valueWei = (string)($params['value'] ?? '0');
        $data = ltrim($params['data'] ?? '', '0x');
        $chainId = (int)($params['chain_id'] ?? 1);

        $fields = [
            self::encodeQuantity($nonce),
            self::encodeQuantity($gasPriceWei),
            self::encodeQuantity($gasLimit),
            hex2bin($to),
            self::encodeQuantity($valueWei),
            $data !== '' ? hex2bin($data) : '',
            self::encodeQuantity($chainId),
            '',
            '',
        ];

        $unsignedRlp = self::encodeRlpList($fields);
        $txHashHex = Keccak::hash($unsignedRlp);

        $sig = Secp256k1::signRfc6979($privHex, $txHashHex);
        $v = $sig['v'] + ($chainId * 2 + 35);

        $signedFields = [
            self::encodeQuantity($nonce),
            self::encodeQuantity($gasPriceWei),
            self::encodeQuantity($gasLimit),
            hex2bin($to),
            self::encodeQuantity($valueWei),
            $data !== '' ? hex2bin($data) : '',
            self::encodeQuantity($v),
            self::encodeQuantity(gmp_init($sig['r'], 16)),
            self::encodeQuantity(gmp_init($sig['s'], 16)),
        ];

        return '0x' . bin2hex(self::encodeRlpList($signedFields));
    }

    public static function buildErc20TransferData(string $toAddress, string $amountWei): string
    {
        $toClean = str_pad(strtolower(ltrim($toAddress, '0x')), 64, '0', STR_PAD_LEFT);
        $amountHex = str_pad(gmp_strval(gmp_init($amountWei, 10), 16), 64, '0', STR_PAD_LEFT);
        return 'a9059cbb' . $toClean . $amountHex;
    }

    private static function encodeQuantity($val): string
    {
        if ($val instanceof \GMP) {
            $gmp = $val;
        } else {
            $gmp = gmp_init((string)$val, 10);
        }
        if (gmp_cmp($gmp, 0) === 0) return '';
        $hex = gmp_strval($gmp, 16);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        return hex2bin($hex);
    }

    private static function encodeRlpItem(string $item): string
    {
        $len = strlen($item);
        if ($len === 1 && ord($item) < 0x80) return $item;
        if ($len <= 55) return chr(0x80 + $len) . $item;
        $lenBytes = self::encodeLength($len);
        return chr(0xb7 + strlen($lenBytes)) . $lenBytes . $item;
    }

    private static function encodeRlpList(array $items): string
    {
        $payload = '';
        foreach ($items as $item) {
            $payload .= self::encodeRlpItem($item);
        }
        $len = strlen($payload);
        if ($len <= 55) return chr(0xc0 + $len) . $payload;
        $lenBytes = self::encodeLength($len);
        return chr(0xf7 + strlen($lenBytes)) . $lenBytes . $payload;
    }

    private static function encodeLength(int $len): string
    {
        $hex = dechex($len);
        if (strlen($hex) % 2 !== 0) $hex = '0' . $hex;
        return hex2bin($hex);
    }
}