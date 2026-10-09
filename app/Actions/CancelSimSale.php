<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\SimSale;
use Illuminate\Support\Facades\DB;

class CancelSimSale
{
    public static function handle(SimSale $sale, ?int $userId, ?string $reason = null): SimSale
    {
        return DB::transaction(function () use ($sale, $userId, $reason) {
            $sale = SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('This SIM sale has already been cancelled.');
            }

            $snapshot = [
                'network' => $sale->network,
                'sim_type' => $sale->sim_type->value,
                'sim_form' => $sale->sim_form->value,
                'is_duplicate' => $sale->is_duplicate,
                'sim_number' => $sale->sim_number,
                'amount' => (float) $sale->amount,
                'fee' => (float) $sale->fee,
                'discount' => (float) $sale->discount,
                'total' => (float) $sale->total,
                'customer_id' => $sale->customer_id,
            ];

            $sale->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $sale->shop_id,
                'revisionable_type' => SimSale::class,
                'revisionable_id' => $sale->id,
                'action' => RevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $sale;
        });
    }
}
