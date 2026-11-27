<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\StockInPaymentSource;
use App\Enums\VendorCreditSource;
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
 * unchanged (VendorPayments). Whatever can't be taken off unpaid stock stays
 * `remaining`, to take off later stock or to be refunded in money.
 */
class VendorCredits
{
    public const METHOD = 'vendor_credit';

    /** @param  list<int>  $preferBatchIds  batches to take it off first (the goods' own, for a debit note) */
    public static function issue(int $vendorId, float $amount, VendorCreditSource $source, ?int $sourceId, ?int $userId, ?string $note = null, bool $apply = true, array $preferBatchIds = []): VendorCredit
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
                'status' => RecordStatus::Active,
                'user_id' => $userId,
            ]);

            if ($apply) {
                self::applyToStock($credit, $userId, $preferBatchIds);
            }

            return $credit->fresh();
        });
    }

    /** Takes what's left of a credit off the vendor's unpaid stock. Returns the amount applied. */
    public static function applyToStock(VendorCredit $credit, ?int $userId, array $preferBatchIds = []): float
    {
        return DB::transaction(function () use ($credit, $userId, $preferBatchIds) {
            $credit = VendorCredit::whereKey($credit->id)->lockForUpdate()->firstOrFail();

            if ($credit->status !== RecordStatus::Active || (float) $credit->remaining <= 0) {
                return 0.0;
            }

            $applied = VendorPayments::payOldestFirst($credit->vendor_id, (float) $credit->remaining, StockInPaymentSource::VendorCredit, self::METHOD, null, $userId, $credit->sourceLabel(), $preferBatchIds);
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

            if ($credit->status !== RecordStatus::Active || $amount <= 0 || $amount > round((float) $credit->remaining, 2)) {
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

            if ($credit->status !== RecordStatus::Active) {
                return;
            }

            if ($credit->refunds()->exists()) {
                throw ValidationException::withMessages(['credit' => 'The vendor has already paid some of this credit back, so it can\'t be undone.']);
            }

            VendorPayments::undo($credit->applied_to ?? [], StockInPaymentSource::VendorCredit, self::METHOD, null, $userId, 'Undone: '.$credit->sourceLabel());
            $credit->update(['status' => RecordStatus::Cancelled, 'remaining' => 0]);
        });
    }

    /** What the vendor holds for the shop, across all active credits. */
    public static function available(int $vendorId): float
    {
        return round((float) VendorCredit::query()->where('vendor_id', $vendorId)->where('status', RecordStatus::Active->value)->sum('remaining'), 2);
    }
}
