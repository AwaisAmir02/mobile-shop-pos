<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Records a payment against any model sharing the amount_paid/total/
 * payment_status shape — Wallet Load, Balance Load, Bill Payment, Repair,
 * NADRA Verification, and SIM Sale all use this exact shape.
 */
class RecordModulePayment
{
    public static function handle(Model $record, float $amount, ?string $method = null, ?int $shopAccountId = null, ?int $userId = null): void
    {
        // Dated, with where the money went, for Cash & Balances (Wallet Loads know their own way).
        RecordCounterPayment::handle($record, $amount, $method, $shopAccountId, $userId);

        $newAmountPaid = (float) $record->amount_paid + $amount;

        $record->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $record->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);
    }
}
