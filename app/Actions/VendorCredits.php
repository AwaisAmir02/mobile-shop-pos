<?php

namespace App\Actions;

use App\Enums\PaymentStatus;
use App\Enums\StockInPaymentSource;
use App\Models\StockIn;
use App\Models\Vendor;
use App\Models\VendorCredit;
use App\Models\VendorCreditRefund;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money a vendor owes the shop back: a debit note (W10), a settled scheme
 * claim (W9), or a cheque that paid more than was owed (W6).
 *
 * What the shop owes a vendor is still only ever what its Stock In rows say
 * (Vendor::totalOwed). A credit reaches that figure the one way any payment
 * does: a stock-in payment row (method vendor_credit) on an unpaid batch, so
 * nothing is counted twice and every existing screen and the ledger read it
 * unchanged. Whatever can't be taken off unpaid stock stays `remaining`,
 * to take off later stock or to be refunded in money.
 */
class VendorCredits
{
    public const METHOD = 'vendor_credit';

    /** @param  list<int>  $preferBatchIds  batches to take it off first (the goods' own, for a debit note) */
    public static function issue(int $vendorId, float $amount, string $source, ?int $sourceId, ?int $userId, ?string $note = null, bool $apply = true, array $preferBatchIds = []): VendorCredit
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'A credit needs an amount.']);
        }

        return DB::transaction(function () use ($vendorId, $amount, $source, $sourceId, $userId, $note, $apply, $preferBatchIds) {
            $credit = VendorCredit::create([
                'shop_id' => Vendor::withoutGlobalScopes()->whereKey($vendorId)->value('shop_id'),
                'vendor_id' => $vendorId,
                'source' => $source,
                'source_id' => $sourceId,
                'amount' => $amount,
                'remaining' => $amount,
                'entry_date' => now()->toDateString(),
                'applied_to' => [],
                'note' => $note,
                'status' => 'active',
                'user_id' => $userId,
            ]);

            if ($apply) {
                self::applyToStock($credit, $userId, $preferBatchIds);
            }

            return $credit->fresh();
        });
    }

    /** The vendor's unpaid batches, oldest first, preferred ones before the rest. */
    protected static function unpaidBatches(int $vendorId, array $preferBatchIds = [])
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
    public static function owedTo(int $vendorId): float
    {
        return round((float) self::unpaidBatches($vendorId)->sum(fn (StockIn $batch) => $batch->amountOwed()), 2);
    }

    /**
     * Pays the vendor's unpaid batches with $amount, oldest first, through
     * RecordStockInPayment (one row per batch). Returns [[stock_in_id, amount]].
     *
     * @return list<array{stock_in_id: int, amount: float}>
     */
    public static function payBatches(int $vendorId, float $amount, StockInPaymentSource $source, ?string $method, ?int $accountId, ?int $userId, string $note, array $preferBatchIds = []): array
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

    /** Undoes payBatches(): a negative row on each batch, dated today. */
    public static function unpayBatches(array $applied, StockInPaymentSource $source, ?string $method, ?int $accountId, ?int $userId, string $note): void
    {
        foreach ($applied as $part) {
            $batch = StockIn::withoutGlobalScopes()->find($part['stock_in_id']);

            if ($batch !== null && round((float) $part['amount'], 2) > 0) {
                RecordStockInPayment::add($batch, -round((float) $part['amount'], 2), $userId, $source, null, $method, $accountId, $note);
            }
        }
    }

    /** Takes what's left of a credit off the vendor's unpaid stock. Returns the amount applied. */
    public static function applyToStock(VendorCredit $credit, ?int $userId, array $preferBatchIds = []): float
    {
        return DB::transaction(function () use ($credit, $userId, $preferBatchIds) {
            $credit = VendorCredit::whereKey($credit->id)->lockForUpdate()->firstOrFail();

            if ($credit->status !== 'active' || (float) $credit->remaining <= 0) {
                return 0.0;
            }

            $applied = self::payBatches($credit->vendor_id, (float) $credit->remaining, StockInPaymentSource::VendorCredit, self::METHOD, null, $userId, $credit->sourceLabel(), $preferBatchIds);
            $total = round(array_sum(array_column($applied, 'amount')), 2);

            $credit->update([
                'remaining' => round((float) $credit->remaining - $total, 2),
                'applied_to' => array_merge($credit->applied_to ?? [], $applied),
            ]);

            return $total;
        });
    }

    /** The vendor paid some of the credit back in money. */
    public static function refund(VendorCredit $credit, float $amount, ?string $method, ?int $shopAccountId, ?int $userId): VendorCreditRefund
    {
        return DB::transaction(function () use ($credit, $amount, $method, $shopAccountId, $userId) {
            $credit = VendorCredit::whereKey($credit->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 2);

            if ($credit->status !== 'active' || $amount <= 0 || $amount > round((float) $credit->remaining, 2)) {
                throw ValidationException::withMessages(['refundAmount' => 'Enter up to Rs '.number_format((float) $credit->remaining, 2).', what is left of this credit.']);
            }

            $refund = $credit->refunds()->create([
                'shop_id' => $credit->shop_id,
                'amount' => $amount,
                'payment_method' => $method,
                'shop_account_id' => $shopAccountId,
                'refund_date' => now()->toDateString(),
                'user_id' => $userId,
            ]);

            $credit->update(['remaining' => round((float) $credit->remaining - $amount, 2)]);

            return $refund;
        });
    }

    /** Undoes a credit: what it took off stock comes back as owed. Refused once refunded. */
    public static function cancel(VendorCredit $credit, ?int $userId): void
    {
        DB::transaction(function () use ($credit, $userId) {
            $credit = VendorCredit::whereKey($credit->id)->lockForUpdate()->firstOrFail();

            if ($credit->status !== 'active') {
                return;
            }

            if ($credit->refunds()->exists()) {
                throw ValidationException::withMessages(['credit' => 'The vendor has already paid some of this credit back, so it can\'t be undone.']);
            }

            self::unpayBatches($credit->applied_to ?? [], StockInPaymentSource::VendorCredit, self::METHOD, null, $userId, 'Undone: '.$credit->sourceLabel());
            $credit->update(['status' => 'cancelled', 'remaining' => 0]);
        });
    }

    /** What the vendor holds for the shop, across all active credits. */
    public static function available(int $vendorId): float
    {
        return round((float) VendorCredit::query()->where('vendor_id', $vendorId)->where('status', 'active')->sum('remaining'), 2);
    }
}
