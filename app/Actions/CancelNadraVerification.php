<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\NadraVerification;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;

class CancelNadraVerification
{
    public static function handle(NadraVerification $verification, ?int $userId, ?string $reason = null): NadraVerification
    {
        return DB::transaction(function () use ($verification, $userId, $reason) {
            $verification = NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($verification->isCancelled()) {
                throw new \RuntimeException('This verification has already been cancelled.');
            }

            $snapshot = [
                'phone_number' => $verification->phone_number,
                'cnic_number' => $verification->cnic_number,
                'amount' => (float) $verification->amount,
                'fee' => (float) $verification->fee,
                'discount' => (float) $verification->discount,
                'total' => (float) $verification->total,
                'customer_id' => $verification->customer_id,
            ];

            $verification->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $verification->shop_id,
                'revisionable_type' => NadraVerification::class,
                'revisionable_id' => $verification->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $verification;
        });
    }
}
