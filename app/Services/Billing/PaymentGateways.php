<?php

namespace App\Services\Billing;

use InvalidArgumentException;

class PaymentGateways
{
    public function current(): PaymentGateway
    {
        return $this->for((string) config('billing.gateway'));
    }

    public function for(string $key): PaymentGateway
    {
        return match ($key) {
            ManualBankTransferGateway::KEY => app(ManualBankTransferGateway::class),
            default => throw new InvalidArgumentException("Unknown payment gateway [{$key}]."),
        };
    }
}
