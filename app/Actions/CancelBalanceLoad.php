<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\BalanceLoad;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;

class CancelBalanceLoad
{
    public static function handle(BalanceLoad $load, ?int $userId, ?string $reason = null): BalanceLoad
    {
        return DB::transaction(function () use ($load, $userId, $reason) {
            $load = BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->isCancelled()) {
                throw new \RuntimeException('This balance load has already been cancelled.');
            }

            $snapshot = [
                'network' => $load->network,
                'load_type' => $load->load_type->value,
                'phone_number' => $load->phone_number,
                'amount' => (float) $load->amount,
                'fee' => (float) $load->fee,
                'discount' => (float) $load->discount,
                'total' => (float) $load->total,
                'customer_id' => $load->customer_id,
            ];

            $load->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $load->shop_id,
                'revisionable_type' => BalanceLoad::class,
                'revisionable_id' => $load->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $load;
        });
    }
}
