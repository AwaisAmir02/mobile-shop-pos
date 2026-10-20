<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\ServicePayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateServicePayment
{
    /**
     * @param  array{service_category_id: int, service_item_id: int, amount: float, customer_id: ?int}  $fields
     */
    public static function handle(ServicePayment $payment, array $fields, ?int $userId): ServicePayment
    {
        return DB::transaction(function () use ($payment, $fields, $userId) {
            $payment = ServicePayment::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->isCancelled()) {
                throw new \RuntimeException('A cancelled service payment cannot be edited.');
            }

            if ($fields['amount'] < (float) $payment->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New amount (Rs '.number_format($fields['amount'], 2).') is less than Rs '.number_format((float) $payment->amount_paid, 2).' already paid against this service.',
                ]);
            }

            if ($fields['customer_id'] === null && $fields['amount'] > (float) $payment->amount_paid) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required while this service still has a due balance.',
                ]);
            }

            $snapshot = [
                'service_category_id' => $payment->service_category_id,
                'service_item_id' => $payment->service_item_id,
                'amount' => (float) $payment->amount,
                'customer_id' => $payment->customer_id,
            ];

            $payment->update([
                'service_category_id' => $fields['service_category_id'],
                'service_item_id' => $fields['service_item_id'],
                'amount' => $fields['amount'],
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $payment->shop_id,
                'revisionable_type' => ServicePayment::class,
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
