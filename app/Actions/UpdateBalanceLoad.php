<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\BalanceLoad;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateBalanceLoad
{
    /**
     * @param  array{network: string, load_type: string, phone_number: ?string, amount: float, fee: float, discount: float, customer_id: ?int}  $fields
     */
    public static function handle(BalanceLoad $load, array $fields, ?int $userId): BalanceLoad
    {
        return DB::transaction(function () use ($load, $fields, $userId) {
            $load = BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->isCancelled()) {
                throw new \RuntimeException('A cancelled balance load cannot be edited.');
            }

            $newTotal = max(0.0, $fields['amount'] + $fields['fee'] - $fields['discount']);

            if ($newTotal < (float) $load->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $load->amount_paid, 2).' already recorded against this balance load.',
                ]);
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
                'network' => $fields['network'],
                'load_type' => $fields['load_type'],
                'phone_number' => $fields['phone_number'],
                'amount' => $fields['amount'],
                'fee' => $fields['fee'],
                'discount' => $fields['discount'],
                'total' => $newTotal,
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $load->shop_id,
                'revisionable_type' => BalanceLoad::class,
                'revisionable_id' => $load->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $load;
        });
    }
}
