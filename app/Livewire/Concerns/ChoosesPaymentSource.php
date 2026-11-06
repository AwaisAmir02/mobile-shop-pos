<?php

namespace App\Livewire\Concerns;

use App\Enums\SalePaymentMethod;
use App\Ledger\LedgerSources;
use App\Models\CounterServicePayment;
use App\Models\ShopAccount;
use App\Support\BatchExpiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The optional "paid from" choice, shown only to shops with the ledger on. Blank
 * means not recorded, which is fine: that money stays in Not Classified.
 */
trait ChoosesPaymentSource
{
    public string $paidFrom = '';

    /** @return array{0: ?string, 1: ?int} payment method, shop account */
    protected function paidFromParts(): array
    {
        if ($this->paidFrom === SalePaymentMethod::Cash->value) {
            return [SalePaymentMethod::Cash->value, null];
        }

        // Scoped to this shop, so a forged id just means "not recorded".
        $account = is_numeric($this->paidFrom) ? ShopAccount::query()->find((int) $this->paidFrom) : null;

        return $account ? [SalePaymentMethod::Account->value, $account->id] : [null, null];
    }

    /** For counter services, which keep only a running amount paid. */
    protected function notePaymentSource(Model $record, float $amount): void
    {
        [$method, $accountId] = $this->paidFromParts();
        $this->paidFrom = '';

        if ($method === null || round($amount, 2) <= 0 || ! Auth::user()->shop?->ledger_enabled) {
            return;
        }

        CounterServicePayment::create([
            'shop_id' => $record->shop_id,
            'source_type' => LedgerSources::documentFor($record)[0],
            'source_id' => $record->id,
            'user_id' => Auth::id(),
            'amount' => round($amount, 2),
            'paid_on' => BatchExpiry::today(),
            'payment_method' => $method,
            'shop_account_id' => $accountId,
        ]);
    }
}
