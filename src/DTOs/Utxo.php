<?php

namespace BlockchainSdk\DTOs;

class Utxo
{
    public function __construct(
        public readonly string $txid,
        public readonly int $vout,
        public readonly int $valueSatoshis,
        public readonly ?string $scriptPubKeyHex = null
    ) {}
}