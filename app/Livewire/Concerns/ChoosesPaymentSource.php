<?php

namespace App\Livewire\Concerns;

use App\Enums\SalePaymentMethod;
use App\Actions\RecordCounterPayment;
use App\Models\ShopAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The "Paid From" / "Received Into" choice: cash, one of the shop's accounts,
 * or not recorded. Every shop sees it (Cash & Balances needs to know where
 * money went); it starts on Cash, the usual case. The ledger, for shops that
 * have it on, reads the same choice.
 */
trait ChoosesPaymentSource
{
    public string $paidFrom = 'cash';

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
        $this->paidFrom = 'cash';

        RecordCounterPayment::handle($record, $amount, $method, $accountId, Auth::id());
    }
}
