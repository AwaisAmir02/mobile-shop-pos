<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\ServicePayment;
use Illuminate\Support\Facades\DB;

class CancelServicePayment
{
    public static function handle(ServicePayment $payment, ?int $userId, ?string $reason = null): ServicePayment
    {
        return DB::transaction(function () use ($payment, $userId, $reason) {
            $payment = ServicePayment::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->isCancelled()) {
                throw new \RuntimeException('This service payment has already been cancelled.');
            }

            $snapshot = [
                'service_category_id' => $payment->service_category_id,
                'service_item_id' => $payment->service_item_id,
                'amount' => (float) $payment->amount,
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
                'revisionable_type' => ServicePayment::class,
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
