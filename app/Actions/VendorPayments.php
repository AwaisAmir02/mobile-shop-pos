<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\StockInPaymentSource;
use App\Models\StockIn;
use Illuminate\Support\Collection;

/**
 * Paying a vendor a sum that isn't tied to one batch: their unpaid Stock In,
 * oldest first, one stock-in payment row per batch (RecordStockInPayment).
 * What the shop owes a vendor is only ever what its Stock In rows say, so
 * every way of paying a lump (a cleared cheque, a contra, a vendor credit)
 * goes through here and every screen and the ledger read it unchanged.
 * Undoing is a negative row on each batch it paid, dated today.
 */
class VendorPayments
{
    /** @return Collection<int, StockIn> the vendor's unpaid batches, oldest first, preferred ones before the rest */
    public static function unpaidBatches(int $vendorId, array $preferBatchIds = []): Collection
    {
        return StockIn::query()
            ->where('vendor_id', $vendorId)
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->whereNotNull('total_cost')
            ->orderBy('stock_date')->orderBy('id')
            ->get()
            ->filter(fn (StockIn $batch) => $batch->amountOwed() > 0)
            ->sortBy(fn (StockIn $batch) => in_array($batch->id, $preferBatchIds, true) ? 0 : 1)
            ->values();
    }

    /** What the vendor is owed in total, as unpaid batches. */
    public static function owed(int $vendorId): float
    {
        return round((float) self::unpaidBatches($vendorId)->sum(fn (StockIn $batch) => $batch->amountOwed()), 2);
    }

    /**
     * Pays the vendor's unpaid batches with $amount, oldest first (or the
     * preferred batches first). Stops when the money or the debt runs out.
     *
     * @param  list<int>  $preferBatchIds
     * @return list<array{stock_in_id: int, amount: float}> what went on each batch, for undo()
     */
    public static function payOldestFirst(int $vendorId, float $amount, StockInPaymentSource $source, ?string $method, ?int $accountId, ?int $userId, string $note, array $preferBatchIds = []): array
    {
        $left = round($amount, 2);
        $applied = [];

        foreach (self::unpaidBatches($vendorId, $preferBatchIds) as $batch) {
            if ($left <= 0) {
                break;
            }

            $take = round(min($left, $batch->amountOwed()), 2);
            RecordStockInPayment::add($batch, $take, $userId, $source, null, $method, $accountId, $note);
            $applied[] = ['stock_in_id' => $batch->id, 'amount' => $take];
            $left = round($left - $take, 2);
        }

        return $applied;
    }

    /** Undoes payOldestFirst(): a negative row on each batch, dated today. */
    public static function undo(array $applied, StockInPaymentSource $source, ?string $method, ?int $accountId, ?int $userId, string $note): void
    {
        foreach ($applied as $part) {
            $batch = StockIn::withoutGlobalScopes()->find($part['stock_in_id']);

            if ($batch !== null && round((float) $part['amount'], 2) > 0) {
                RecordStockInPayment::add($batch, -round((float) $part['amount'], 2), $userId, $source, null, $method, $accountId, $note);
            }
        }
    }
}
