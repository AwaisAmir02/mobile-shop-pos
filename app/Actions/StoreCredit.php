<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Enums\SalePaymentMethod;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\Shop;
use App\Models\StoreCreditDraw;
use App\Models\StoreCreditEntry;
use App\Support\BatchExpiry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's store credit, kept entirely apart from udhaar.
 *
 * Credit comes in only from a return, as an issue that keeps its own
 * remaining amount, like a stock batch. It goes out by being spent on a
 * sale, handed over as money (a cash-out, only where the shop gives money
 * back), or expiring. Each of those draws from the issues that expire soonest
 * (credit that never expires last, oldest first), and the draws say which.
 * Expired credit can't be spent; the daily task writes it off.
 */
class StoreCredit
{
    /** What the customer holds: every active entry added up. */
    public static function balance(int $customerId): float
    {
        return round((float) StoreCreditEntry::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('status', RecordStatus::Active->value)
            ->sum('amount'), 2);
    }

    /** What can be used today: credit not yet past its expiry date. */
    public static function spendable(int $customerId, ?string $today = null): float
    {
        return round((float) self::lots($customerId, $today ?? BatchExpiry::today())->sum('remaining'), 2);
    }

    /** Credit from a return. */
    public static function issue(SaleReturn $return, int $customerId, float $amount, ?int $userId): StoreCreditEntry
    {
        $shop = Shop::findOrFail($return->shop_id);
        $date = $return->return_date->toDateString();
        $days = $shop->store_credit_expiry_days;

        return StoreCreditEntry::create([
            'shop_id' => $return->shop_id,
            'branch_id' => $return->branch_id,
            'customer_id' => $customerId,
            'user_id' => $userId,
            'type' => StoreCreditEntry::ISSUE,
            'amount' => round($amount, 2),
            'remaining' => round($amount, 2),
            'expires_on' => $days ? CarbonImmutable::parse($date)->addDays((int) $days)->toDateString() : null,
            'entry_date' => $date,
            'sale_return_id' => $return->id,
        ]);
    }

    /** Credit paying for (part of) a sale, through its Store credit payment. */
    public static function spend(Sale $sale, SalePayment $payment, float $amount, ?int $userId): StoreCreditEntry
    {
        if ($sale->customer_id === null) {
            throw ValidationException::withMessages(['storeCredit' => 'Choose the customer whose store credit is being used.']);
        }

        return self::takeOut($sale->customer_id, $amount, [
            'shop_id' => $sale->shop_id,
            'user_id' => $userId,
            'type' => StoreCreditEntry::SPEND,
            'entry_date' => $payment->payment_date->toDateString(),
            'sale_id' => $sale->id,
            'sale_payment_id' => $payment->id,
        ], 'storeCredit');
    }

    /**
     * Credit handed over as money. Only where the shop gives money back on
     * returns, and recorded as a cash-out, never as a return's refund.
     */
    public static function cashOut(Customer $customer, float $amount, ?string $method, ?int $shopAccountId, ?int $userId, ?string $note = null): StoreCreditEntry
    {
        $shop = Shop::findOrFail($customer->shop_id);

        if (! $shop->cash_refunds_allowed) {
            throw ValidationException::withMessages(['cashOutAmount' => 'This shop doesn\'t hand back money, so store credit can only be spent.']);
        }

        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['cashOutAmount' => 'Enter how much to hand over.']);
        }

        $source = SalePaymentMethod::paymentAttributes($method, $shopAccountId, $customer->shop_id, $amount);

        return self::takeOut($customer->id, $amount, [
            'shop_id' => $customer->shop_id,
            'user_id' => $userId,
            'type' => StoreCreditEntry::CASH_OUT,
            'entry_date' => BatchExpiry::today(),
            'payment_method' => $source['payment_method'],
            'shop_account_id' => $source['shop_account_id'],
            'note' => filled($note) ? mb_substr(trim($note), 0, 255) : null,
        ], 'cashOutAmount');
    }

    /**
     * Writes off whatever is left of credit past its expiry date: one entry
     * per issue, dated the day after it expired.
     *
     * @return int how many issues were written off
     */
    public static function expireDue(?string $today = null, ?int $shopId = null): int
    {
        $today ??= BatchExpiry::today();
        $count = 0;

        $ids = StoreCreditEntry::withoutGlobalScopes()
            ->where('type', StoreCreditEntry::ISSUE)
            ->where('status', RecordStatus::Active->value)
            ->where('remaining', '>', 0)
            ->whereNotNull('expires_on')
            ->where('expires_on', '<', $today)
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->pluck('id');

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count) {
                $issue = StoreCreditEntry::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();

                if ($issue === null || $issue->isCancelled() || (float) $issue->remaining <= 0) {
                    return;
                }

                $left = round((float) $issue->remaining, 2);

                $entry = StoreCreditEntry::create([
                    'shop_id' => $issue->shop_id,
                    'branch_id' => $issue->branch_id,
                    'customer_id' => $issue->customer_id,
                    'type' => StoreCreditEntry::EXPIRE,
                    'amount' => -$left,
                    'entry_date' => $issue->expires_on->copy()->addDay()->toDateString(),
                    'sale_return_id' => $issue->sale_return_id,
                ]);

                StoreCreditDraw::create(['shop_id' => $issue->shop_id, 'entry_id' => $entry->id, 'issue_id' => $issue->id, 'amount' => $left]);
                $issue->update(['remaining' => 0]);
                $count++;
            });
        }

        return $count;
    }

    /**
     * Undoes a spend, cash-out or expiry (the credit goes back to the issues
     * it came from), or an issue that hasn't been used at all.
     */
    public static function cancel(StoreCreditEntry $entry, ?int $userId, ?string $reason = null, string $errorKey = 'cancelReason'): void
    {
        DB::transaction(function () use ($entry, $userId, $reason, $errorKey) {
            $entry = StoreCreditEntry::withoutGlobalScopes()->whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($entry->isCancelled()) {
                return;
            }

            if ($entry->type === StoreCreditEntry::ISSUE) {
                if (round((float) $entry->remaining, 2) < round((float) $entry->amount, 2)) {
                    $used = round((float) $entry->amount - (float) $entry->remaining, 2);

                    throw ValidationException::withMessages([$errorKey => 'Rs '.number_format($used, 2).' of the store credit from this return has already been used (spent, cashed out or expired). Undo that first.']);
                }

                $entry->update(['remaining' => 0]);
            } else {
                foreach ($entry->draws()->get() as $draw) {
                    StoreCreditEntry::withoutGlobalScopes()->whereKey($draw->issue_id)->lockForUpdate()->firstOrFail()
                        ->increment('remaining', (float) $draw->amount);
                }
            }

            $entry->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => filled($reason) ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $entry->shop_id,
                'revisionable_type' => StoreCreditEntry::class,
                'revisionable_id' => $entry->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $entry->only(['customer_id', 'type', 'amount', 'remaining', 'entry_date', 'sale_return_id', 'sale_id', 'sale_payment_id']),
                'created_at' => now(),
            ]);
        });
    }

    /** Cancels the credit a sale spent, when the sale itself is cancelled. */
    public static function cancelSpendsFor(Sale $sale, ?int $userId): void
    {
        foreach (StoreCreditEntry::withoutGlobalScopes()->where('sale_id', $sale->id)->where('type', StoreCreditEntry::SPEND)->where('status', RecordStatus::Active->value)->get() as $entry) {
            self::cancel($entry, $userId, 'Sale '.$sale->invoiceNumber().' cancelled');
        }
    }

    /** Active, unexpired issues with something left, soonest expiry first, never-expiring last. */
    protected static function lots(int $customerId, string $today)
    {
        return StoreCreditEntry::withoutGlobalScopes()
            ->where('customer_id', $customerId)
            ->where('type', StoreCreditEntry::ISSUE)
            ->where('status', RecordStatus::Active->value)
            ->where('remaining', '>', 0)
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $today))
            ->orderByRaw('expires_on IS NULL')
            ->orderBy('expires_on')
            ->orderBy('id');
    }

    /** @param array<string, mixed> $attributes */
    protected static function takeOut(int $customerId, float $amount, array $attributes, string $errorKey): StoreCreditEntry
    {
        $amount = round($amount, 2);

        return DB::transaction(function () use ($customerId, $amount, $attributes, $errorKey) {
            $lots = self::lots($customerId, BatchExpiry::today())->lockForUpdate()->get();
            $available = round((float) $lots->sum('remaining'), 2);

            if ($amount > $available) {
                throw ValidationException::withMessages([$errorKey => 'Only Rs '.number_format($available, 2).' of store credit is available.']);
            }

            $entry = StoreCreditEntry::create($attributes + ['customer_id' => $customerId, 'amount' => -$amount]);
            $left = $amount;

            foreach ($lots as $lot) {
                if ($left <= 0) {
                    break;
                }

                $take = round(min($left, (float) $lot->remaining), 2);
                $lot->update(['remaining' => round((float) $lot->remaining - $take, 2)]);
                StoreCreditDraw::create(['shop_id' => $entry->shop_id, 'entry_id' => $entry->id, 'issue_id' => $lot->id, 'amount' => $take]);
                $left = round($left - $take, 2);
            }

            return $entry;
        });
    }
}
