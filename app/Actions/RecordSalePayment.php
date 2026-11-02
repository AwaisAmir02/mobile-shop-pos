<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Models\Sale;

class RecordSalePayment
{
    public static function handle(Sale $sale, float $amount, ?int $userId, ?string $paymentMethod = null, ?int $shopAccountId = null): void
    {
        $sale->payments()->create(array_merge([
            'user_id' => $userId,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ], SalePaymentMethod::paymentAttributes($paymentMethod, $shopAccountId, $sale->shop_id, $amount)));
    }
}
