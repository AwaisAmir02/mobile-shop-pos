<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\NadraVerification;
use App\Models\Scopes\ExcludesCancelledScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateNadraVerification
{
    /**
     * @param  array{phone_number: string, cnic_number: string, amount: float, fee: float, discount: float, customer_id: ?int}  $fields
     */
    public static function handle(NadraVerification $verification, array $fields, ?int $userId): NadraVerification
    {
        return DB::transaction(function () use ($verification, $fields, $userId) {
            $verification = NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($verification->id)->lockForUpdate()->firstOrFail();

            if ($verification->isCancelled()) {
                throw new \RuntimeException('A cancelled verification cannot be edited.');
            }

            $newTotal = max(0.0, $fields['amount'] + $fields['fee'] - $fields['discount']);

            if ($newTotal < (float) $verification->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $verification->amount_paid, 2).' already paid against this verification.',
                ]);
            }

            if ($fields['customer_id'] === null && $newTotal > (float) $verification->amount_paid) {
                throw ValidationException::withMessages([
                    'customer_id' => 'A customer is required while this verification still has a due balance.',
                ]);
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
                'phone_number' => $fields['phone_number'],
                'cnic_number' => $fields['cnic_number'],
                'amount' => $fields['amount'],
                'fee' => $fields['fee'],
                'discount' => $fields['discount'],
                'total' => $newTotal,
                'customer_id' => $fields['customer_id'],
            ]);

            ModuleRevision::create([
                'shop_id' => $verification->shop_id,
                'revisionable_type' => NadraVerification::class,
                'revisionable_id' => $verification->id,
                'action' => RevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $verification;
        });
    }
}
