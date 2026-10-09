<?php

namespace App\Actions;

use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\WalletLoad;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateWalletLoad
{
    /**
     * @param  array{shop_account_id: int, customer_id: ?int, account_name: ?string, account_number: ?string, amount: float, fee: float, discount: float, fee_included_in_amount: bool}  $fields
     */
    public static function handle(WalletLoad $load, array $fields, ?int $userId): WalletLoad
    {
        return DB::transaction(function () use ($load, $fields, $userId) {
            $load = WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->isCancelled()) {
                throw new \RuntimeException('A cancelled wallet load cannot be edited.');
            }

            $newTotal = $fields['fee_included_in_amount']
                ? max(0.0, $fields['amount'] - $fields['discount'])
                : max(0.0, $fields['amount'] + $fields['fee'] - $fields['discount']);

            $newNetAmount = $fields['fee_included_in_amount']
                ? max(0.0, $fields['amount'] - $fields['fee'])
                : $fields['amount'];

            if ($newTotal < (float) $load->amount_paid) {
                throw ValidationException::withMessages([
                    'amount' => 'New total (Rs '.number_format($newTotal, 2).') is less than Rs '.number_format((float) $load->amount_paid, 2).' already recorded against this wallet load.',
                ]);
            }

            $snapshot = [
                'provider' => $load->provider,
                'account_name' => $load->account_name,
                'account_number' => $load->account_number,
                'shop_account_id' => $load->shop_account_id,
                'amount' => (float) $load->amount,
                'fee' => (float) $load->fee,
                'discount' => (float) $load->discount,
                'fee_included_in_amount' => $load->fee_included_in_amount,
                'net_amount' => (float) $load->net_amount,
                'total' => (float) $load->total,
                'customer_id' => $load->customer_id,
            ];

            $load->update([
                'shop_account_id' => $fields['shop_account_id'],
                'customer_id' => $fields['customer_id'],
                'account_name' => $fields['account_name'],
                'account_number' => $fields['account_number'],
                'amount' => $fields['amount'],
                'fee' => $fields['fee'],
                'discount' => $fields['discount'],
                'fee_included_in_amount' => $fields['fee_included_in_amount'],
                'net_amount' => $newNetAmount,
                'total' => $newTotal,
            ]);

            ModuleRevision::create([
                'shop_id' => $load->shop_id,
                'revisionable_type' => WalletLoad::class,
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
