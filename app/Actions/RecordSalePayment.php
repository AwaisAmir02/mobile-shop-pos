<?php

namespace App\Actions;

use App\Models\Sale;

class RecordSalePayment
{
    public static function handle(Sale $sale, float $amount, ?int $userId): void
    {
        $sale->payments()->create([
            'user_id' => $userId,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
        ]);
    }
}
