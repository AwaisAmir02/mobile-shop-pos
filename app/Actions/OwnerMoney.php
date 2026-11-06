<?php

namespace App\Actions;

use App\Models\Concerns\HasCancellation;
use App\Models\ModuleRevision;
use App\Models\OwnerWithdrawal;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\WithholdingPayment;
use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner taking money out, and paying over salary held for someone else.
 * Both exist only with the ledger on, since they're there to keep it true.
 */
class OwnerMoney
{
    public static function withdraw(Shop $shop, float $amount, string $date, ?string $method, ?int $accountId, ?string $note, ?int $userId): OwnerWithdrawal
    {
        self::assertLedgerOn($shop);
        self::assertAmount($amount);

        return OwnerWithdrawal::create(self::fields($shop, $amount, $date, $method, $accountId, $note, $userId));
    }

    public static function payOverWithheld(Shop $shop, float $amount, string $date, ?string $method, ?int $accountId, ?string $note, ?int $userId): WithholdingPayment
    {
        self::assertLedgerOn($shop);
        self::assertAmount($amount);

        return DB::transaction(function () use ($shop, $amount, $date, $method, $accountId, $note, $userId) {
            Shop::whereKey($shop->id)->lockForUpdate()->first();

            $held = self::withheldBalance($shop->id);

            if (round($amount, 2) > $held) {
                throw ValidationException::withMessages(['amount' => 'Only Rs '.number_format($held, 2).' is being held.']);
            }

            return WithholdingPayment::create(self::fields($shop, $amount, $date, $method, $accountId, $note, $userId));
        });
    }

    /** Held on paid payslips, less what's already been paid over. */
    public static function withheldBalance(int $shopId): float
    {
        $held = (float) DB::table('payslips')->where('shop_id', $shopId)->where('status', 'paid')->sum('held_deductions');
        $paid = (float) DB::table('withholding_payments')->where('shop_id', $shopId)->where('status', '!=', RecordStatus::Cancelled->value)->sum('amount');

        return round($held - $paid, 2);
    }

    public static function cancel(OwnerWithdrawal|WithholdingPayment $record, ?int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($record, $userId, $reason) {
            $record = $record::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($record->id)->lockForUpdate()->firstOrFail();

            if ($record->isCancelled()) {
                throw new \RuntimeException('This has already been cancelled.');
            }

            $snapshot = ['amount' => (float) $record->amount, 'paid_on' => $record->paid_on->toDateString(), 'payment_method' => $record->payment_method, 'shop_account_id' => $record->shop_account_id, 'note' => $record->note];

            $record->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $userId, 'cancel_reason' => filled($reason) ? $reason : null]);

            ModuleRevision::create([
                'shop_id' => $record->shop_id,
                'revisionable_type' => $record::class,
                'revisionable_id' => $record->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => filled($reason) ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);
        });
    }

    protected static function fields(Shop $shop, float $amount, string $date, ?string $method, ?int $accountId, ?string $note, ?int $userId): array
    {
        return ['shop_id' => $shop->id, 'user_id' => $userId, 'amount' => round($amount, 2), 'paid_on' => $date, 'payment_method' => $method, 'shop_account_id' => $accountId, 'note' => filled($note) ? $note : null];
    }

    protected static function assertAmount(float $amount): void
    {
        if (round($amount, 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter an amount above zero.']);
        }
    }

    public static function assertLedgerOn(Shop $shop): void
    {
        $shop = Shop::findOrFail($shop->id);

        if (! $shop->ledger_enabled || $shop->ledger_ready_at === null || $shop->ledger_frozen_at !== null) {
            throw ValidationException::withMessages(['amount' => 'This is only for shops with the ledger running.']);
        }
    }
}
