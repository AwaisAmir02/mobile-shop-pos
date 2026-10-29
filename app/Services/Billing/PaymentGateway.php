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
     * @return array{type: 'instructions'|'redirect', instructions?: string, url?: string}
     */
    public function nextStep(SubscriptionPayment $payment): array;
}
