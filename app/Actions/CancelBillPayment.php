<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\BillPayment;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;

class CancelBillPayment
{
    public static function handle(BillPayment $payment, ?int $userId, ?string $reason = null): BillPayment
    {
        return DB::transaction(function () use ($payment, $userId, $reason) {
            $payment = BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->isCancelled()) {
                throw new \RuntimeException('This bill payment has already been cancelled.');
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
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $payment->shop_id,
                'revisionable_type' => BillPayment::class,
                'revisionable_id' => $payment->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $payment;
        });
    }
}
