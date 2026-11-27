<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Models\JobCard;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Advance deposits on a booking or a job card. The money is the customer's
 * until it is used on their bill (a "deposit" payment, no money moving then),
 * refunded, or kept by the shop when they don't turn up. It is never part of
 * what they owe: it sits beside Udhaar and dues like store credit does
 * (docs/customer-balances.md).
 */
class CustomerDeposits
{
    public static function receive(Customer $customer, float $amount, string $date, ?string $method, ?int $shopAccountId, ?int $userId, ?Appointment $appointment = null, ?JobCard $jobCard = null, ?string $note = null): CustomerDeposit
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['depositAmount' => 'Enter the deposit received.']);
        }

        $attributes = SalePaymentMethod::paymentAttributes($method ?? 'cash', $shopAccountId, $customer->shop_id, $amount);

        $deposit = new CustomerDeposit([
            'customer_id' => $customer->id,
            'appointment_id' => $appointment?->id,
            'job_card_id' => $jobCard?->id,
            'amount' => $amount,
            'received_on' => $date,
            'payment_method' => $attributes['payment_method'],
            'shop_account_id' => $attributes['shop_account_id'],
            'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : null,
            'user_id' => $userId,
        ]);
        $deposit->shop_id = $customer->shop_id;
        $deposit->save();

        return $deposit;
    }

    /** Pays part of a bill from a deposit the bill's customer holds. */
    public static function spend(Sale $sale, int $depositId, float $amount, ?int $userId): void
    {
        $deposit = CustomerDeposit::withoutGlobalScopes()->where('shop_id', $sale->shop_id)->lockForUpdate()->find($depositId);

        if ($deposit === null || $deposit->status === RecordStatus::Cancelled) {
            throw ValidationException::withMessages(['prepaid' => 'That deposit can\'t be used.']);
        }

        if ($sale->customer_id === null || $deposit->customer_id !== (int) $sale->customer_id) {
            throw ValidationException::withMessages(['prepaid' => 'A deposit can only pay its own customer\'s bill.']);
        }

        if (round($amount, 2) > $deposit->remaining()) {
            throw ValidationException::withMessages(['prepaid' => $deposit->number().' has Rs '.number_format($deposit->remaining(), 2).' left.']);
        }

        $sale->payments()->create([
            'shop_id' => $sale->shop_id,
            'user_id' => $userId,
            'amount' => round($amount, 2),
            'payment_date' => now()->toDateString(),
            'payment_method' => SalePaymentMethod::Deposit->value,
            'customer_deposit_id' => $deposit->id,
        ]);
    }

    /** Gives what is left back to the customer. */
    public static function refund(CustomerDeposit $deposit, float $amount, string $date, ?string $method, ?int $shopAccountId): CustomerDeposit
    {
        return DB::transaction(function () use ($deposit, $amount, $date, $method, $shopAccountId) {
            $deposit = CustomerDeposit::withoutGlobalScopes()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $amount = round($amount, 2);

            if ($amount <= 0 || $amount > $deposit->remaining()) {
                throw ValidationException::withMessages(['refundAmount' => 'Refund up to the Rs '.number_format($deposit->remaining(), 2).' left.']);
            }

            if ((float) $deposit->refunded > 0) {
                throw ValidationException::withMessages(['refundAmount' => 'This deposit was already refunded once.']);
            }

            $attributes = SalePaymentMethod::paymentAttributes($method ?? 'cash', $shopAccountId, $deposit->shop_id, $amount);
            $deposit->update(['refunded' => $amount, 'refunded_on' => $date, 'refund_method' => $attributes['payment_method'], 'refund_account_id' => $attributes['shop_account_id']]);

            return $deposit;
        });
    }

    /** The shop keeps what is left (a no-show on a non-refundable booking): it becomes income. */
    public static function forfeit(CustomerDeposit $deposit, string $date): CustomerDeposit
    {
        return DB::transaction(function () use ($deposit, $date) {
            $deposit = CustomerDeposit::withoutGlobalScopes()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $left = $deposit->remaining();

            if ($left <= 0) {
                throw ValidationException::withMessages(['deposit' => 'Nothing is left on this deposit.']);
            }

            $deposit->update(['forfeited' => round((float) $deposit->forfeited + $left, 2), 'forfeited_on' => $date]);

            return $deposit;
        });
    }

    /** Undoes a deposit entered by mistake, while none of it has been used, refunded or kept. */
    public static function cancel(CustomerDeposit $deposit): void
    {
        DB::transaction(function () use ($deposit) {
            $deposit = CustomerDeposit::withoutGlobalScopes()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();

            if ($deposit->used() > 0 || (float) $deposit->refunded > 0 || (float) $deposit->forfeited > 0) {
                throw ValidationException::withMessages(['deposit' => 'Part of this deposit was used, refunded or kept, so it can\'t be cancelled.']);
            }

            $deposit->update(['status' => RecordStatus::Cancelled, 'cancelled_at' => now()]);
        });
    }

    /** What a customer holds in deposits right now. */
    public static function heldFor(int $customerId): float
    {
        return round(CustomerDeposit::withoutGlobalScopes()->where('customer_id', $customerId)->where('status', RecordStatus::Active->value)->get()->sum(fn (CustomerDeposit $d) => $d->remaining()), 2);
    }
}
