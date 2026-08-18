<?php

namespace BlockchainSdk\Drivers\Bitcoin;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Bech32;
use BlockchainSdk\Crypto\Secp256k1;

class BitcoinTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $tx = $params['tx'];
        $privHex = ltrim($params['private_key'], '0x');
        $pub = Secp256k1::privateKeyToPublicKey($privHex);
        $compressedPubKey = $pub['compressed'];

        foreach ($tx['inputs'] as $i => &$input) {
            $sighash = self::getWitnessSighash($tx, $i);
            if (!$sighash) {
                throw new \RuntimeException("Failed to generate witness sighash for input {$i}");
            }

            $sig = Secp256k1::signRfc6979($privHex, bin2hex($sighash));
            $der = self::encodeDer(gmp_init($sig['r'], 16), gmp_init($sig['s'], 16));
            $sigHex = bin2hex($der . "\x01"); // SIGHASH_ALL

            $input['witness'] = [
                $sigHex,
                $compressedPubKey
            ];
        }

        return self::serializeSignedTx($tx);
    }

    public static function buildUnsignedSegwitTx(
        array $utxos,
        string $toAddress,
        int $amountSat,
        string $changeAddress,
        int $feeRateSatVb = 20
    ): ?array {
        $scriptPubKeyDest = self::addressToScriptPubKey($toAddress);
        $scriptPubKeyChange = self::addressToScriptPubKey($changeAddress);

        if (!$scriptPubKeyDest) return null;

        $inputs = [];
        $totalInputSat = 0;
        foreach ($utxos as $utxo) {
            $inputs[] = [
                'txid' => $utxo['txid'],
                'vout' => (int)$utxo['vout'],
                'sequence' => 0xffffffff,
                'amount' => (int)$utxo['value'],
                'scriptPubKey' => $scriptPubKeyChange,
                'witness' => []
            ];
            $totalInputSat += (int)$utxo['value'];
            if ($totalInputSat >= $amountSat + 50000) break;
        }

        if ($totalInputSat < $amountSat) return null;

        $numInputs = count($inputs);
        $numOutputs = 2;
        $estimatedVSize = ($numInputs * 68) + ($numOutputs * 31) + 11;
        $feeSat = $estimatedVSize * $feeRateSatVb;

        $changeSat = $totalInputSat - $amountSat - $feeSat;

        $outputs = [
            [
                'amount' => $amountSat,
                'scriptPubKey' => $scriptPubKeyDest
            ]
        ];

        if ($changeSat >= 546) {
            $outputs[] = [
                'amount' => $changeSat,
                'scriptPubKey' => $scriptPubKeyChange
            ];
        } else {
            $numOutputs = 1;
            $estimatedVSize = ($numInputs * 68) + ($numOutputs * 31) + 11;
            $feeSat = $estimatedVSize * $feeRateSatVb;
            $amountSat = $totalInputSat - $feeSat;
            if ($amountSat <= 0) return null;
            $outputs[0]['amount'] = $amountSat;
        }

        return [
            'version' => 1,
            'inputs' => $inputs,
            'outputs' => $outputs,
            'locktime' => 0
        ];
    }

    public static function addressToScriptPubKey(string $address): ?string
    {
        if (str_starts_with($address, 'bc1q') || str_starts_with($address, 'tb1q')) {
            $decoded = self::decodeSegwitAddress($address);
            if ($decoded && strlen($decoded['program']) === 20) {
                return '0014' . bin2hex($decoded['program']);
            }
        }
        return null;
    }

    private static function decodeSegwitAddress(string $address): ?array
    {
        $pos = strrpos($address, '1');
        if ($pos === false) return null;
        $hrp = substr($address, 0, $pos);
        $dataStr = substr($address, $pos + 1);
        $charset = Bech32::CHARSET;
        $data = [];
        for ($i = 0; $i < strlen($dataStr); $i++) {
            $val = strpos($charset, $dataStr[$i]);
            if ($val === false) return null;
            $data[] = $val;
        }
        $data = array_slice($data, 0, -6);
        if (empty($data)) return null;
        $version = $data[0];
        $programBits = array_slice($data, 1);
        $program = Bech32::convertBits($programBits, 5, 8, false);
        return [
            'version' => $version,
            'program' => pack('C*', ...$program),
        ];
    }

    private static function getWitnessSighash(array $tx, int $inputIndex): ?string
    {
        $input = $tx['inputs'][$inputIndex];
        $scriptPubKey = $input['scriptPubKey'];
        if (strlen($scriptPubKey) !== 44 || substr($scriptPubKey, 0, 4) !== '0014') return null;
        $pubkeyHash = substr($scriptPubKey, 4);

        $versionBin = pack('V', $tx['version']);

        $prevouts = '';
        foreach ($tx['inputs'] as $in) {
            $prevouts .= strrev(hex2bin($in['txid'])) . pack('V', $in['vout']);
        }
        $hashPrevouts = hash('sha256', hash('sha256', $prevouts, true), true);

        $sequences = '';
        foreach ($tx['inputs'] as $in) {
            $sequences .= pack('V', $in['sequence']);
        }
        $hashSequence = hash('sha256', hash('sha256', $sequences, true), true);

        $outpoint = strrev(hex2bin($input['txid'])) . pack('V', $input['vout']);
        $scriptCode = hex2bin('1976a914' . $pubkeyHash . '88ac');
        $valueBin = pack('P', $input['amount']);
        $sequenceBin = pack('V', $input['sequence']);

        $outputs = '';
        foreach ($tx['outputs'] as $out) {
            $outputs .= pack('P', $out['amount'])
                     . self::writeVarInt(strlen(hex2bin($out['scriptPubKey'])))
                     . hex2bin($out['scriptPubKey']);
        }
        $hashOutputs = hash('sha256', hash('sha256', $outputs, true), true);

        $lockTimeBin = pack('V', $tx['locktime']);
        $hashTypeBin = pack('V', 1); // SIGHASH_ALL

        $preimage = $versionBin
                  . $hashPrevouts
                  . $hashSequence
                  . $outpoint
                  . $scriptCode
                  . $valueBin
                  . $sequenceBin
                  . $hashOutputs
                  . $lockTimeBin
                  . $hashTypeBin;

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
        if ($i < 0xfd) return chr($i);
        if ($i <= 0xffff) return "\xfd" . pack('v', $i);
        if ($i <= 0xffffffff) return "\xfe" . pack('V', $i);
        return "\xff" . pack('P', $i);
    }

    private static function encodeDer(\GMP $r, \GMP $s): string
    {
        $rBin = hex2bin(str_pad(gmp_strval($r, 16), 64, '0', STR_PAD_LEFT));
        $sBin = hex2bin(str_pad(gmp_strval($s, 16), 64, '0', STR_PAD_LEFT));

        $rBin = ltrim($rBin, "\x00");
        if (ord($rBin[0]) & 0x80) $rBin = "\x00" . $rBin;

        $sBin = ltrim($sBin, "\x00");
        if (ord($sBin[0]) & 0x80) $sBin = "\x00" . $sBin;

        $rDer = "\x02" . chr(strlen($rBin)) . $rBin;
        $sDer = "\x02" . chr(strlen($sBin)) . $sBin;

        $payload = $rDer . $sDer;
        return "\x30" . chr(strlen($payload)) . $payload;
    }
}