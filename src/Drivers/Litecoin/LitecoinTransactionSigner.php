<?php

namespace BlockchainSdk\Drivers\Litecoin;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Base58;
use BlockchainSdk\Crypto\Bech32;
use BlockchainSdk\Crypto\Secp256k1;
use InvalidArgumentException;

class LitecoinTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $tx = $params['tx'];
        $privHex = LitecoinWalletGenerator::privateKeyHex($params['private_key']);
        $pub = Secp256k1::privateKeyToPublicKey($privHex);
        $compressedPubKey = $pub['compressed'];

        foreach ($tx['inputs'] as $i => &$input) {
            $sighash = self::getWitnessSighash($tx, $i);
            if (! $sighash) {
                throw new \RuntimeException("Failed to generate witness sighash for input {$i}");
            }

            $sig = Secp256k1::signRfc6979($privHex, bin2hex($sighash));
            $der = self::encodeDer(gmp_init($sig['r'], 16), gmp_init($sig['s'], 16));
            $sigHex = bin2hex($der."\x01"); // SIGHASH_ALL

            $input['witness'] = [
                $sigHex,
                $compressedPubKey,
            ];
        }

        return self::serializeSignedTx($tx);
    }

    public static function addressToScript(string $address): string
    {
        if (strtolower($address) !== $address && strtoupper($address) !== $address) {
            if (str_starts_with(strtolower($address), 'ltc1')) {
                throw new InvalidArgumentException('Mixed-case Litecoin SegWit address.');
            }
        }
        if (str_starts_with(strtolower($address), 'ltc1')) {
            $decoded = Bech32::decode($address);
            if ($decoded && $decoded[0] === 'ltc' && $decoded[1][0] === 0) {
                $program = pack('C*', ...Bech32::convertBits(array_slice($decoded[1], 1), 5, 8, false));
                if (in_array(strlen($program), [20, 32], true)
                    && strtolower($address) === Bech32::encodeSegwit('ltc', 0, $program)) {
                    return '00'.sprintf('%02x', strlen($program)).bin2hex($program);
                }
            }
        } else {
            $payload = Base58::decodeCheck($address);
            if (strlen($payload) === 21) {
                $hash = bin2hex(substr($payload, 1));
                if (ord($payload[0]) === 48) {
                    return '76a914'.$hash.'88ac';
                }
                if (ord($payload[0]) === 50) {
                    return 'a914'.$hash.'87';
                }
            }
        }

        throw new InvalidArgumentException('Unsupported or invalid Litecoin mainnet address.');
    }

    public static function transactionId(array $tx): string
    {
        $raw = pack('V', $tx['version']).self::writeVarInt(count($tx['inputs']));
        foreach ($tx['inputs'] as $input) {
            $raw .= strrev(hex2bin($input['txid'])).pack('V', $input['vout'])."\x00".pack('V', $input['sequence']);
        }
        $raw .= self::writeVarInt(count($tx['outputs']));
        foreach ($tx['outputs'] as $output) {
            $script = hex2bin($output['scriptPubKey']);
            $raw .= pack('P', $output['amount']).self::writeVarInt(strlen($script)).$script;
        }
        $raw .= pack('V', $tx['locktime']);

        return bin2hex(strrev(hash('sha256', hash('sha256', $raw, true), true)));
    }

    private static function getWitnessSighash(array $tx, int $inputIndex): ?string
    {
        $input = $tx['inputs'][$inputIndex];
        $scriptPubKey = $input['scriptPubKey'];
        if (strlen($scriptPubKey) !== 44 || substr($scriptPubKey, 0, 4) !== '0014') {
            return null;
        }
        $pubkeyHash = substr($scriptPubKey, 4);

        $versionBin = pack('V', $tx['version']);

        $prevouts = '';
        foreach ($tx['inputs'] as $in) {
            $prevouts .= strrev(hex2bin($in['txid'])).pack('V', $in['vout']);
        }
        $hashPrevouts = hash('sha256', hash('sha256', $prevouts, true), true);

        $sequences = '';
        foreach ($tx['inputs'] as $in) {
            $sequences .= pack('V', $in['sequence']);
        }
        $hashSequence = hash('sha256', hash('sha256', $sequences, true), true);

        $outpoint = strrev(hex2bin($input['txid'])).pack('V', $input['vout']);
        $scriptCode = hex2bin('1976a914'.$pubkeyHash.'88ac');
        $valueBin = pack('P', $input['amount']);
        $sequenceBin = pack('V', $input['sequence']);

        $outputs = '';
        foreach ($tx['outputs'] as $out) {
            $outputs .= pack('P', $out['amount'])
                     .self::writeVarInt(strlen(hex2bin($out['scriptPubKey'])))
                     .hex2bin($out['scriptPubKey']);
        }
        $hashOutputs = hash('sha256', hash('sha256', $outputs, true), true);

        $lockTimeBin = pack('V', $tx['locktime']);
        $hashTypeBin = pack('V', 1); // SIGHASH_ALL

        $preimage = $versionBin
                  .$hashPrevouts
                  .$hashSequence
                  .$outpoint
                  .$scriptCode
                  .$valueBin
                  .$sequenceBin
                  .$hashOutputs
                  .$lockTimeBin
                  .$hashTypeBin;

        return hash('sha256', hash('sha256', $preimage, true), true);
    }

    private static function serializeSignedTx(array $tx): string
    {
        $raw = pack('V', $tx['version']);
        $raw .= "\x00\x01"; // SegWit marker & flag

        $raw .= self::writeVarInt(count($tx['inputs']));
        foreach ($tx['inputs'] as $input) {
            $raw .= strrev(hex2bin($input['txid']));
            $raw .= pack('V', $input['vout']);
            $raw .= "\x00"; // scriptSig is empty for P2WPKH
            $raw .= pack('V', $input['sequence']);
        }

        $raw .= self::writeVarInt(count($tx['outputs']));
        foreach ($tx['outputs'] as $output) {
            $raw .= pack('P', $output['amount']);
            $spk = hex2bin($output['scriptPubKey']);
            $raw .= self::writeVarInt(strlen($spk));
            $raw .= $spk;
        }

        foreach ($tx['inputs'] as $input) {
            $witness = $input['witness'];
            $raw .= self::writeVarInt(count($witness));
            foreach ($witness as $itemHex) {
                $item = hex2bin($itemHex);
                $raw .= self::writeVarInt(strlen($item));
                $raw .= $item;
            }
        }

        $raw .= pack('V', $tx['locktime']);

        return bin2hex($raw);
    }

    private static function writeVarInt(int $i): string
    {
        if ($i < 0xFD) {
            return chr($i);
        }
        if ($i <= 0xFFFF) {
            return "\xfd".pack('v', $i);
        }
        if ($i <= 0xFFFFFFFF) {
            return "\xfe".pack('V', $i);
        }

        return "\xff".pack('P', $i);
    }

    private static function encodeDer(\GMP $r, \GMP $s): string
    {
        $rBin = hex2bin(str_pad(gmp_strval($r, 16), 64, '0', STR_PAD_LEFT));
        $sBin = hex2bin(str_pad(gmp_strval($s, 16), 64, '0', STR_PAD_LEFT));

        $rBin = ltrim($rBin, "\x00");
        if (ord($rBin[0]) & 0x80) {
            $rBin = "\x00".$rBin;
        }

        $sBin = ltrim($sBin, "\x00");
        if (ord($sBin[0]) & 0x80) {
            $sBin = "\x00".$sBin;
        }

        $rDer = "\x02".chr(strlen($rBin)).$rBin;
        $sDer = "\x02".chr(strlen($sBin)).$sBin;

        $payload = $rDer.$sDer;

        return "\x30".chr(strlen($payload)).$payload;
    }
}
