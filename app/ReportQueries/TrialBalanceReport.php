<?php

namespace App\ReportQueries;

use App\Models\LedgerAccount;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Every account's balance up to a date, from the journal of the shop's current epoch. */
class TrialBalanceReport
{
    public function __construct(protected Shop $shop, protected string $asOf = '') {}

    /** @return Collection<int, array{code: string, name: string, type: string, debit: float, credit: float}> */
    public function rows(): Collection
    {
        $totals = DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.shop_id', $this->shop->id)
            ->where('ledger_entries.epoch', $this->shop->ledger_epoch)
            ->when($this->asOf, fn ($query) => $query->whereDate('ledger_entries.entry_date', '<=', $this->asOf))
            ->groupBy('ledger_lines.ledger_account_id')
            ->selectRaw('ledger_lines.ledger_account_id as account_id, SUM(ledger_lines.amount_paise) as paise')
            ->pluck('paise', 'account_id');

        return LedgerAccount::withoutGlobalScopes()
            ->where('shop_id', $this->shop->id)
            ->orderBy('code')
            ->get()
            ->map(function (LedgerAccount $account) use ($totals) {
                $paise = (int) ($totals[$account->id] ?? 0);

                return [
                    'code' => $account->code,
                    'name' => $account->name,
                    'type' => $account->type,
                    'debit' => $paise > 0 ? $paise / 100 : 0.0,
                    'credit' => $paise < 0 ? -$paise / 100 : 0.0,
                ];
            })
            ->filter(fn (array $row) => $row['debit'] != 0 || $row['credit'] != 0)
            ->values();
    }
}
