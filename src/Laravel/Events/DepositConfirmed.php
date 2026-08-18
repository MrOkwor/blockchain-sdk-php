<?php

namespace BlockchainSdk\Laravel\Events;

use BlockchainSdk\Laravel\Models\BlockchainSdkDeposit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DepositConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BlockchainSdkDeposit $deposit
    ) {}
}