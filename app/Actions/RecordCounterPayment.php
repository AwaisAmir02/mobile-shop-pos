<?php

namespace App\Actions;

use App\Ledger\LedgerSources;
use App\Models\CounterServicePayment;
use App\Models\WalletLoad;
use App\Support\BatchExpiry;
use Illuminate\Database\Eloquent\Model;

/**
 * Notes when and how a customer paid on a counter service (Repair, Bill,
 * Wallet/Balance Load, SIM, NADRA, Service), so Cash & Balances can put the
 * money in cash or the right account on the right day. Every shop records
 * these now; the ledger, when a shop has it on, reads the same rows.
 *
 * A Wallet Load's customer money always goes the same way (cash for Cash In,
 * the load's own account for Cash Out), so its method is never asked for.
 * A null method is still dated: it shows as "Not recorded".
 */
class RecordCounterPayment
{
    public static function handle(Model $record, float $amount, ?string $method = null, ?int $shopAccountId = null, ?int $userId = null): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            return;
        }

        if ($record instanceof WalletLoad) {
            [$method, $shopAccountId] = $record->customerPaysThrough();
        }

        if ($method !== 'account') {
            $shopAccountId = null;
        }

        CounterServicePayment::create([
            'shop_id' => $record->shop_id,
            'source_type' => LedgerSources::documentFor($record)[0],
            'source_id' => $record->id,
            'user_id' => $userId,
            'amount' => $amount,
            'paid_on' => BatchExpiry::today(),
            'payment_method' => $method,
            'shop_account_id' => $shopAccountId,
        ]);
    }
}
