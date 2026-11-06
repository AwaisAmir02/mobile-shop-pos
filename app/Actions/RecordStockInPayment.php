<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\RecordStatus;
use App\Enums\StockInPaymentSource;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use Illuminate\Support\Facades\DB;

/**
 * The one way money paid to a vendor is recorded after a Stock-In exists:
 * amount_paid moves, and one dated StockInPayment row records the change, so a
 * Stock-In's rows always add up to its amount_paid.
 */
class RecordStockInPayment
{
    public static function add(StockIn $entry, float $amount, ?int $userId): StockIn
    {
        return DB::transaction(function () use ($entry, $amount, $userId) {
            $entry = self::lock($entry);

            $previousAmountPaid = (float) $entry->amount_paid;
            $newAmountPaid = $previousAmountPaid + $amount;

            $entry->update([
                'amount_paid' => $newAmountPaid,
                'payment_status' => $entry->total_cost !== null && $newAmountPaid >= (float) $entry->total_cost
                    ? PaymentStatus::Paid
                    : PaymentStatus::Partial,
            ]);

            self::recordRow($entry, (float) $entry->amount_paid - $previousAmountPaid, StockInPaymentSource::Payment, $userId);

            return $entry;
        });
    }

    public static function markPaid(StockIn $entry, ?int $userId): StockIn
    {
        return DB::transaction(function () use ($entry, $userId) {
            $entry = self::lock($entry);

            $previousAmountPaid = (float) $entry->amount_paid;

            $entry->update([
                'payment_status' => PaymentStatus::Paid,
                'amount_paid' => $entry->total_cost ?? $entry->amount_paid,
            ]);

            self::recordRow($entry, (float) $entry->amount_paid - $previousAmountPaid, StockInPaymentSource::MarkPaid, $userId);

            return $entry;
        });
    }

    protected static function lock(StockIn $entry): StockIn
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($entry->id)->lockForUpdate()->firstOrFail();
        abort_if($entry->status === RecordStatus::Cancelled, 403);

        return $entry;
    }

    /** The exact change just stored, after decimal rounding. No change, no row. */
    protected static function recordRow(StockIn $entry, float $change, StockInPaymentSource $source, ?int $userId): void
    {
        if (round($change, 2) == 0.0) {
            return;
        }

        $entry->payments()->create([
            'shop_id' => $entry->shop_id,
            'user_id' => $userId,
            'amount' => round($change, 2),
            'payment_date' => now()->toDateString(),
            'source' => $source,
        ]);
    }
}
