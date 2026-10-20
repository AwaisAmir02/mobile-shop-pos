<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\UdhaarTransaction;
use Illuminate\Support\Facades\DB;

class CancelUdhaarTransaction
{
    public static function handle(UdhaarTransaction $transaction, ?int $userId, ?string $reason = null): UdhaarTransaction
    {
        return DB::transaction(function () use ($transaction, $userId, $reason) {
            $transaction = UdhaarTransaction::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($transaction->id)->lockForUpdate()->firstOrFail();

            if (! $transaction->isActionable()) {
                throw new \RuntimeException('This transaction can no longer be cancelled.');
            }

            $snapshot = [
                'type' => $transaction->type->value,
                'amount' => (float) $transaction->amount,
                'transaction_date' => $transaction->transaction_date->toDateString(),
                'note' => $transaction->note,
            ];

            $transaction->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $transaction->shop_id,
                'revisionable_type' => UdhaarTransaction::class,
                'revisionable_id' => $transaction->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $transaction;
        });
    }
}
