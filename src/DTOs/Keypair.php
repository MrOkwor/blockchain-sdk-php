<?php

namespace BlockchainSdk\DTOs;

class Keypair
{
    public function __construct(
        public readonly string $address,
        public readonly string $privateKey,
        public readonly ?string $publicKey = null,
        public readonly ?string $mnemonic = null
    ) {}

    public function toArray(): array
    {
        return [
            'address'     => $this->address,
            'private_key' => $this->privateKey,
            'public_key'  => $this->publicKey,
            'mnemonic'    => $this->mnemonic,
        ];
    }
}