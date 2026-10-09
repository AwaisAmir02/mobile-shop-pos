<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\SimSale;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSimSale
{
    /**
     * @param  array{network: string, sim_type: string, sim_form: string, is_duplicate: bool, sim_number: string, amount: float, fee: float, discount: float, customer_id: ?int}  $fields
     */
    public static function handle(SimSale $sale, array $fields, ?int $userId): SimSale
    {
        return DB::transaction(function () use ($sale, $fields, $userId) {
            $sale = SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('A cancelled SIM sale cannot be edited.');
            }

            $newTotal = max(0.0, $fields['amount'] + $fields['fee'] - $fields['discount']);

            if ($newTotal < (float) $sale->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $sale->amount_paid, 2).' already paid against this SIM sale.',
                ]);
            }

            if ($fields['customer_id'] === null && $newTotal > (float) $sale->amount_paid) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required while this SIM sale still has a due balance.',
                ]);
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
                'network' => $fields['network'],
                'sim_type' => $fields['sim_type'],
                'sim_form' => $fields['sim_form'],
                'is_duplicate' => $fields['is_duplicate'],
                'sim_number' => $fields['sim_number'],
                'amount' => $fields['amount'],
                'fee' => $fields['fee'],
                'discount' => $fields['discount'],
                'total' => $newTotal,
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $sale->shop_id,
                'revisionable_type' => SimSale::class,
                'revisionable_id' => $sale->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $sale;
        });
    }
}
