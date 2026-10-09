<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Repair;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;

class CancelRepair
{
    public static function handle(Repair $repair, ?int $userId, ?string $reason = null): Repair
    {
        return DB::transaction(function () use ($repair, $userId, $reason) {
            $repair = Repair::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($repair->isCancelled()) {
                throw new \RuntimeException('This repair has already been cancelled.');
            }

            $snapshot = [
                'category' => $repair->category,
                'sub_category' => $repair->sub_category,
                'description' => $repair->description,
                'amount' => (float) $repair->amount,
                'discount' => (float) $repair->discount,
                'total' => (float) $repair->total,
                'customer_id' => $repair->customer_id,
            ];

            $repair->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $repair->shop_id,
                'revisionable_type' => Repair::class,
                'revisionable_id' => $repair->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $repair;
        });
    }
}
