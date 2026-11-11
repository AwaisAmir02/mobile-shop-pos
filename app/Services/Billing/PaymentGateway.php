<?php

namespace App\Services\Billing;

use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;

interface PaymentGateway
{
    public function key(): string;

    public function label(): string;

    public function start(SubscriptionInvoice $invoice): SubscriptionPayment;

    /**
     * What the owner does next to pay: follow instructions (pay into one of
     * `accounts`, then upload the slip) or go to a gateway's `url`.
     *
     * @return array{type: 'instructions'|'redirect', instructions?: string, accounts?: \Illuminate\Support\Collection<int, \App\Models\PaymentAccount>, url?: string}
     */
    public function nextStep(SubscriptionPayment $payment): array;
}
