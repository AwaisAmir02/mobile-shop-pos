<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\GiftVoucher;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gift vouchers: sold for money now (which the shop then owes as service),
 * spent later by whoever brings the code, part by part. Spending is a
 * "gift_voucher" payment on the bill; cancelling that bill gives the value back.
 */
class GiftVouchers
{
    public static function sell(array $fields, ?int $userId, int $shopId): GiftVoucher
    {
        $amount = round((float) ($fields['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Enter the voucher\'s value.']);
        }

        $attributes = SalePaymentMethod::paymentAttributes($fields['payment_method'] ?? 'cash', $fields['shop_account_id'] ?? null, $shopId, $amount);

        return DB::transaction(function () use ($fields, $userId, $shopId, $amount, $attributes) {
            $voucher = new GiftVoucher([
                'code' => self::newCode($shopId),
                'amount' => $amount,
                'buyer_customer_id' => $fields['buyer_customer_id'] ?? null,
                'buyer_name' => $fields['buyer_name'] ?? null,
                'recipient_name' => $fields['recipient_name'] ?? null,
                'sold_on' => $fields['sold_on'] ?? now()->toDateString(),
                'expires_on' => $fields['expires_on'] ?? null,
                'payment_method' => $attributes['payment_method'],
                'shop_account_id' => $attributes['shop_account_id'],
                'note' => $fields['note'] ?? null,
                'user_id' => $userId,
            ]);
            $voucher->shop_id = $shopId;
            $voucher->save();

            return $voucher;
        });
    }

    /** A voucher by its code, as typed at the register (any case, spaces ignored). */
    public static function find(int $shopId, string $code): ?GiftVoucher
    {
        $code = strtoupper(preg_replace('/\s+/', '', $code));

        return $code === '' ? null : GiftVoucher::withoutGlobalScopes()->where('shop_id', $shopId)->where('code', $code)->first();
    }

    /** Why a voucher can't pay today, or null when it can. */
    public static function problem(?GiftVoucher $voucher, string $date): ?string
    {
        return match (true) {
            $voucher === null => 'No gift voucher has that code.',
            $voucher->status === RecordStatus::Cancelled => 'That voucher was cancelled.',
            $voucher->isExpiredOn($date) => 'That voucher ran out on '.$voucher->expires_on->format('d M Y').'.',
            $voucher->remaining() <= 0 => 'That voucher has been used up.',
            default => null,
        };
    }

    public static function spend(Sale $sale, int $voucherId, float $amount, ?int $userId, string $saleDate): void
    {
        $voucher = GiftVoucher::withoutGlobalScopes()->where('shop_id', $sale->shop_id)->lockForUpdate()->find($voucherId);

        if (($problem = self::problem($voucher, Carbon::parse($saleDate)->toDateString())) !== null) {
            throw ValidationException::withMessages(['prepaid' => $problem]);
        }

        if (round($amount, 2) > $voucher->remaining()) {
            throw ValidationException::withMessages(['prepaid' => 'Voucher '.$voucher->code.' has Rs '.number_format($voucher->remaining(), 2).' left.']);
        }

        $sale->payments()->create([
            'shop_id' => $sale->shop_id,
            'user_id' => $userId,
            'amount' => round($amount, 2),
            'payment_date' => now()->toDateString(),
            'payment_method' => SalePaymentMethod::GiftVoucher->value,
            'gift_voucher_id' => $voucher->id,
        ]);
    }

    /** Takes back a voucher sold by mistake, while none of it is spent (the money is handed back). */
    public static function cancel(GiftVoucher $voucher): void
    {
        DB::transaction(function () use ($voucher) {
            $voucher = GiftVoucher::withoutGlobalScopes()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->used() > 0) {
                throw ValidationException::withMessages(['voucher' => 'Part of this voucher was spent, so it can\'t be cancelled.']);
            }

            $voucher->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now()]);
        });
    }

    /** Eight characters, no 0/O or 1/I, unique in the shop. */
    protected static function newCode(int $shopId): string
    {
        do {
            $code = collect(range(1, 8))->map(fn () => '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 31)])->implode('');
        } while (GiftVoucher::withoutGlobalScopes()->where('shop_id', $shopId)->where('code', $code)->exists());

        return $code;
    }
}
