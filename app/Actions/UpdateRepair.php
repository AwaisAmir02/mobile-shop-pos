<?php

namespace App\Actions;

use App\Models\ModuleRevision;
use App\Enums\RevisionAction;
use App\Models\Repair;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRepair
{
    /**
     * @param  array{category: string, sub_category: ?string, description: string, service_charges: float, products_charges: float, discount: float, customer_id: ?int}  $fields
     */
    public static function handle(Repair $repair, array $fields, ?int $userId): Repair
    {
        return DB::transaction(function () use ($repair, $fields, $userId) {
            $repair = Repair::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($repair->id)->lockForUpdate()->firstOrFail();

            if ($repair->isCancelled()) {
                throw new \RuntimeException('A cancelled repair cannot be edited.');
            }

            $newAmount = $fields['service_charges'] + $fields['products_charges'];
            $newTotal = max(0.0, $newAmount - $fields['discount']);

            if ($newTotal < (float) $repair->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $repair->amount_paid, 2).' already paid against this repair.',
                ]);
            }

            if ($fields['customer_id'] === null && $newTotal > (float) $repair->amount_paid) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required while this repair still has a due balance.',
                ]);
            }

            $snapshot = [
                'category' => $repair->category,
                'sub_category' => $repair->sub_category,
                'description' => $repair->description,
                'amount' => (float) $repair->amount,
                'service_charges' => $repair->service_charges !== null ? (float) $repair->service_charges : null,
                'products_charges' => $repair->products_charges !== null ? (float) $repair->products_charges : null,
                'discount' => (float) $repair->discount,
                'total' => (float) $repair->total,
                'customer_id' => $repair->customer_id,
            ];

            $repair->update([
                'category' => $fields['category'],
                'sub_category' => $fields['sub_category'],
                'description' => $fields['description'],
                'amount' => $newAmount,
                'service_charges' => $fields['service_charges'],
                'products_charges' => $fields['products_charges'],
                'discount' => $fields['discount'],
                'total' => $newTotal,
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $repair->shop_id,
                'revisionable_type' => Repair::class,
                'revisionable_id' => $repair->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $repair;
        });
    }
}
