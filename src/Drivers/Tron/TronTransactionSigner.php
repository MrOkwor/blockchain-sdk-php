<?php

namespace BlockchainSdk\Drivers\Tron;

use BlockchainSdk\Contracts\TransactionSignerInterface;
use BlockchainSdk\Crypto\Keccak;
use BlockchainSdk\Crypto\Secp256k1;

class TronTransactionSigner implements TransactionSignerInterface
{
    public function signTransaction(array $params): string
    {
        $privHex = ltrim($params['private_key'], '0x');
        $rawTxHex = $params['raw_data_hex'];

        $txHashHex = hash('sha256', hex2bin($rawTxHex));
        $sig = Secp256k1::signRfc6979($privHex, $txHashHex);

        $vHex = dechex($sig['v'] + 27);
        if (strlen($vHex) % 2 !== 0) $vHex = '0' . $vHex;

        $signatureHex = $sig['r'] . $sig['s'] . $vHex;

        $txData = $params['transaction_data'] ?? [];
        $txData['signature'] = [$signatureHex];

        return json_encode($txData);
    }
}