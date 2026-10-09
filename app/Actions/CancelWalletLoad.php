<?php

namespace App\Actions;

use App\Enums\RecordStatus;
use App\Enums\RevisionAction;
use App\Models\ModuleRevision;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\WalletLoad;
use Illuminate\Support\Facades\DB;

class CancelWalletLoad
{
    public static function handle(WalletLoad $load, ?int $userId, ?string $reason = null): WalletLoad
    {
        return DB::transaction(function () use ($load, $userId, $reason) {
            $load = WalletLoad::withoutGlobalScope(ExcludesCancelledScope::class)->whereKey($load->id)->lockForUpdate()->firstOrFail();

            if ($load->isCancelled()) {
                throw new \RuntimeException('This wallet load has already been cancelled.');
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
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            ModuleRevision::create([
                'shop_id' => $load->shop_id,
                'revisionable_type' => WalletLoad::class,
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
