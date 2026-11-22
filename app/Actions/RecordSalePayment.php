<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Models\Sale;

class RecordSalePayment
{
    public static function handle(Sale $sale, float $amount, ?int $userId, ?string $paymentMethod = null, ?int $shopAccountId = null): void
    {
        // From the customer's store credit: drawn from it in the same transaction.
        if ($paymentMethod === SalePaymentMethod::StoreCredit->value) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($sale, $amount, $userId) {
                $payment = $sale->payments()->create([
                    'user_id' => $userId,
                    'amount' => $amount,
                    'payment_date' => now()->toDateString(),
                    'payment_method' => SalePaymentMethod::StoreCredit->value,
                ]);

                StoreCredit::spend($sale, $payment, $amount, $userId);
            });

            return;
        }

        $sale->payments()->create(array_merge([
            'user_id' => $userId,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ], SalePaymentMethod::paymentAttributes($paymentMethod, $shopAccountId, $sale->shop_id, $amount)));
    }
}
