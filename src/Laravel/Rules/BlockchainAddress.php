<?php

namespace BlockchainSdk\Laravel\Rules;

use BlockchainSdk\Laravel\Facades\Blockchain;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class BlockchainAddress implements ValidationRule
{
    public function __construct(
        private string $network
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || empty(trim($value))) {
            $fail("The {$attribute} must be a valid string.");
            return;
        }

        if (!Blockchain::validateAddress($this->network, trim($value))) {
            $networkName = ucfirst($this->network);
            $fail("The {$attribute} is not a valid {$networkName} blockchain address.");
        }
    }
}