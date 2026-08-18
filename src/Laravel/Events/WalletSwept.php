<?php

namespace BlockchainSdk\Laravel\Events;

use BlockchainSdk\Laravel\Models\BlockchainSdkSweep;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WalletSwept
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly BlockchainSdkSweep $sweep
    ) {}
}