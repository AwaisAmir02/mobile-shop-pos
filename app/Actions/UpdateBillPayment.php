<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\BillPayment;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBillPayment
{
    /**
     * @param  array{bill_category_id: int, bill_provider_id: int, shop_account_id: int, consumer_number: string, consumer_name: string, amount: float, fee: float, discount: float, customer_id: ?int}  $fields
     */
    public static function handle(BillPayment $payment, array $fields, ?int $userId): BillPayment
    {
        return DB::transaction(function () use ($payment, $fields, $userId) {
            $payment = BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->isCancelled()) {
                throw new \RuntimeException('A cancelled bill payment cannot be edited.');
            }

            $newTotal = max(0.0, $fields['amount'] + $fields['fee'] - $fields['discount']);

            if ($newTotal < (float) $payment->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $payment->amount_paid, 2).' already paid against this bill payment.',
                ]);
            }

            if ($fields['customer_id'] === null && $newTotal > (float) $payment->amount_paid) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required while this bill payment still has a due balance.',
                ]);
            }

            $snapshot = [
                'consumer_number' => $payment->consumer_number,
                'consumer_name' => $payment->consumer_name,
                'bill_category_id' => $payment->bill_category_id,
                'bill_provider_id' => $payment->bill_provider_id,
                'shop_account_id' => $payment->shop_account_id,
                'amount' => (float) $payment->amount,
                'fee' => (float) $payment->fee,
                'discount' => (float) $payment->discount,
                'total' => (float) $payment->total,
                'customer_id' => $payment->customer_id,
            ];

            $payment->update([
                'bill_category_id' => $fields['bill_category_id'],
                'bill_provider_id' => $fields['bill_provider_id'],
                'shop_account_id' => $fields['shop_account_id'],
                'consumer_number' => $fields['consumer_number'],
                'consumer_name' => $fields['consumer_name'],
                'amount' => $fields['amount'],
                'fee' => $fields['fee'],
                'discount' => $fields['discount'],
                'total' => $newTotal,
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $payment->shop_id,
                'revisionable_type' => BillPayment::class,
                'revisionable_id' => $payment->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $payment;
        });
    }
}
