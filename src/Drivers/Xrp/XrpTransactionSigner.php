<?php

namespace BlockchainSdk\Drivers\Xrp;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Secp256k1;
use InvalidArgumentException;

class XrpTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $key = XrpWalletGenerator::privateKeyHex($params['private_key']);
        $transaction = $params['tx'];
        $transaction['SigningPubKey'] = Secp256k1::privateKeyToPublicKey($key)['compressed'];
        if ((new XrpWalletGenerator)->privateKeyToAddress($key) !== $transaction['Account']) {
            throw new InvalidArgumentException('The XRP signing key does not match the sending account.');
        }
        $hash = substr(hash('sha512', hex2bin('53545800'.$this->serialize($transaction))), 0, 64);
        $signature = Secp256k1::signRfc6979($key, $hash);
        $transaction['TxnSignature'] = $this->der($signature['r'], $signature['s']);

        return $this->serialize($transaction);
    }

    /** Serialize direct native/issued Payments and TrustSet in canonical field order. */
    public function serialize(array $transaction): string
    {
        $isTrust = ($transaction['TransactionType'] ?? null) === 'TrustSet';
        $allowed = ['TransactionType', 'Flags', 'Sequence', 'LastLedgerSequence', 'Fee', 'SigningPubKey', 'TxnSignature', 'Account'];
        $allowed = array_merge($allowed, $isTrust ? ['LimitAmount'] : ['DestinationTag', 'Amount', 'SendMax', 'Destination']);
        if (! in_array($transaction['TransactionType'] ?? null, ['Payment', 'TrustSet'], true) || array_diff(array_keys($transaction), $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported XRP transaction fields or type.');
        }
        $hex = $isTrust ? '120014' : '120000';
        foreach (['Flags' => '22', 'Sequence' => '24', 'DestinationTag' => '2E', 'LastLedgerSequence' => '201B'] as $field => $header) {
            if (isset($transaction[$field])) {
                if (! is_int($transaction[$field]) || $transaction[$field] < 0 || $transaction[$field] > 4294967295) {
                    throw new InvalidArgumentException('Invalid XRP '.$field.'.');
                }
                $hex .= $header.bin2hex(pack('N', $transaction[$field]));
            }
        }
        foreach (['Amount' => '61', 'LimitAmount' => '63', 'Fee' => '68', 'SendMax' => '69'] as $field => $header) {
            if (! array_key_exists($field, $transaction)) {
                if ($field === 'Fee' || ($isTrust && $field === 'LimitAmount') || (! $isTrust && $field === 'Amount')) {
                    throw new InvalidArgumentException('Missing XRP '.$field.'.');
                }

                continue;
            }
            if (is_array($transaction[$field])) {
                if ($field === 'Fee') {
                    throw new InvalidArgumentException('XRP fees must use drops.');
                }
                $hex .= $header.XrpAmount::issued($transaction[$field]);

                continue;
            }
            $value = (string) ($transaction[$field] ?? '');
            if (! preg_match('/^\d+$/D', $value) || bccomp($value, '0') <= 0 || bccomp($value, '100000000000000000') > 0) {
                throw new InvalidArgumentException('Invalid XRP drops amount.');
            }
            $hex .= $header.str_pad(gmp_strval(gmp_or(gmp_init($value), gmp_init('4000000000000000', 16)), 16), 16, '0', STR_PAD_LEFT);
        }
        foreach (['SigningPubKey' => '73', 'TxnSignature' => '74'] as $field => $header) {
            if (isset($transaction[$field])) {
                $value = $transaction[$field];
                if (! ctype_xdigit($value) || strlen($value) % 2 !== 0 || strlen($value) > 384) {
                    throw new InvalidArgumentException('Invalid XRP signing blob.');
                }
                $hex .= $header.sprintf('%02x', strlen($value) / 2).$value;
            }
        }
        foreach (['Account' => '81', 'Destination' => '83'] as $field => $header) {
            if ($isTrust && $field === 'Destination') {
                continue;
            }
            $hex .= $header.'14'.bin2hex(XrpWalletGenerator::accountId($transaction[$field]));
        }

        return strtoupper($hex);
    }

    public static function transactionHash(string $blob): string
    {
        return strtoupper(substr(hash('sha512', hex2bin('54584E00'.$blob)), 0, 64));
    }

    private function der(string $r, string $s): string
    {
        $parts = '';
        foreach ([$r, $s] as $number) {
            $bytes = ltrim(hex2bin(str_pad($number, 64, '0', STR_PAD_LEFT)), "\x00");
            if (ord($bytes[0]) & 128) {
                $bytes = "\x00".$bytes;
            }
            $parts .= "\x02".chr(strlen($bytes)).$bytes;
        }

        return bin2hex("\x30".chr(strlen($parts)).$parts);
    }
}
