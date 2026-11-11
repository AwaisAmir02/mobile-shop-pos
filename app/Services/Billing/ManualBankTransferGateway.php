<?php

namespace App\Services\Billing;

use App\Enums\SubscriptionPaymentStatus;
use App\Models\PaymentAccount;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;

class ManualBankTransferGateway implements PaymentGateway
{
    public const KEY = 'manual_bank_transfer';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Bank transfer';
    }

    public function start(SubscriptionInvoice $invoice): SubscriptionPayment
    {
        return SubscriptionPayment::create([
            'shop_id' => $invoice->shop_id,
            'subscription_invoice_id' => $invoice->id,
            'gateway' => self::KEY,
            'status' => SubscriptionPaymentStatus::Pending,
            'amount' => $invoice->total,
        ]);
    }

    /**
     * The accounts set up under Admin > Payment Accounts; the config text is
     * the fallback while there are none.
     */
    public function nextStep(SubscriptionPayment $payment): array
    {
        return [
            'type' => 'instructions',
            'instructions' => (string) config('billing.manual_bank_transfer.instructions'),
            'accounts' => PaymentAccount::active()->ordered()->get(),
        ];
    }
}
