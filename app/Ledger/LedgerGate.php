<?php

namespace App\Ledger;

use App\Models\Shop;

/** Whether a shop is posting, remembered for the request so an ordinary save costs at most one query. */
class LedgerGate
{
    /** @var array<int, bool> */
    protected array $posting = [];

    public function isPosting(?int $shopId): bool
    {
        if ($shopId === null) {
            return false;
        }

        return $this->posting[$shopId] ??= Shop::whereKey($shopId)
            ->where('ledger_enabled', true)
            ->whereNotNull('ledger_started_at')
            ->whereNull('ledger_frozen_at')
            ->exists();
    }

    public function forget(int $shopId): void
    {
        unset($this->posting[$shopId]);
    }
}
