<?php

namespace App\ReportQueries;

use App\Ledger\ChartOfAccounts as Chart;
use App\Models\Branch;
use App\Models\LedgerAccount;
use App\Models\Shop;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The financial statements, read only from the journal of the shop's current
 * epoch. Amounts come back in rupees, shown the way a reader expects: income and
 * liabilities as positive, a contra like Discounts as negative. Fixed layouts on
 * purpose. These need an accountant's review before real shops rely on them.
 */
class LedgerStatements
{
    public const CASH_ACCOUNTS = [Chart::CASH, Chart::BANK];

    public function __construct(protected Shop $shop) {}

    /** @return array<string, int> account id => net paise, debit positive */
    protected function totals(?string $from, ?string $to, ?int $branchId = null, bool $onlyUnallocated = false): array
    {
        return DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.shop_id', $this->shop->id)
            ->where('ledger_entries.epoch', $this->shop->ledger_epoch)
            ->when($from, fn ($query) => $query->whereDate('ledger_entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('ledger_entries.entry_date', '<=', $to))
            ->when($branchId, fn ($query) => $query->where('ledger_lines.branch_id', $branchId))
            ->when($onlyUnallocated, fn ($query) => $query->whereNull('ledger_lines.branch_id'))
            ->groupBy('ledger_lines.ledger_account_id')
            ->selectRaw('ledger_lines.ledger_account_id as account_id, SUM(ledger_lines.amount_paise) as paise')
            ->pluck('paise', 'account_id')
            ->map(fn ($paise) => (int) $paise)
            ->all();
    }

    /** @return Collection<int, LedgerAccount> */
    protected function accounts(): Collection
    {
        return LedgerAccount::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderBy('code')->get();
    }

    /**
     * @return array{income: array<int, array{name: string, amount: float}>, total_income: float, cogs: float, gross_profit: float, expenses: array<int, array{name: string, amount: float}>, total_expenses: float, net_profit: float}
     */
    public function profitAndLoss(?string $from, ?string $to, ?int $branchId = null, bool $onlyUnallocated = false): array
    {
        $totals = $this->totals($from, $to, $branchId, $onlyUnallocated);
        $income = [];
        $expenses = [];
        $cogs = 0;

        foreach ($this->accounts() as $account) {
            $paise = $totals[$account->id] ?? 0;

            if ($account->type === LedgerAccount::INCOME && $paise !== 0) {
                $income[] = ['name' => $account->name, 'amount' => -$paise / 100];
            } elseif ($account->code === Chart::COGS) {
                $cogs = $paise;
            } elseif ($account->type === LedgerAccount::EXPENSE && $paise !== 0) {
                $expenses[] = ['name' => $account->name, 'amount' => $paise / 100];
            }
        }

        $totalIncome = round(array_sum(array_column($income, 'amount')), 2);
        $totalExpenses = round(array_sum(array_column($expenses, 'amount')), 2);
        $gross = round($totalIncome - $cogs / 100, 2);

        return [
            'income' => $income,
            'total_income' => $totalIncome,
            'cogs' => $cogs / 100,
            'gross_profit' => $gross,
            'expenses' => $expenses,
            'total_expenses' => $totalExpenses,
            'net_profit' => round($gross - $totalExpenses, 2),
        ];
    }

    /**
     * Stock and banks are shared, so this is shop-wide only. Profit so far is added
     * to equity, which is what makes the two sides meet.
     *
     * @return array{assets: array, total_assets: float, liabilities: array, total_liabilities: float, equity: array, total_equity: float, balanced: bool}
     */
    public function balanceSheet(?string $asOf): array
    {
        $totals = $this->totals(null, $asOf);
        $sections = [LedgerAccount::ASSET => [], LedgerAccount::LIABILITY => [], LedgerAccount::EQUITY => []];
        $profit = 0;

        foreach ($this->accounts() as $account) {
            $paise = $totals[$account->id] ?? 0;

            if (in_array($account->type, [LedgerAccount::INCOME, LedgerAccount::EXPENSE], true)) {
                $profit -= $paise;
            } elseif ($paise !== 0) {
                $sections[$account->type][] = ['name' => $account->name, 'amount' => ($account->type === LedgerAccount::ASSET ? $paise : -$paise) / 100];
            }
        }

        if ($profit !== 0) {
            $sections[LedgerAccount::EQUITY][] = ['name' => 'Profit Since the Start', 'amount' => $profit / 100];
        }

        $sum = fn (array $rows) => round(array_sum(array_column($rows, 'amount')), 2);
        $assets = $sum($sections[LedgerAccount::ASSET]);
        $liabilities = $sum($sections[LedgerAccount::LIABILITY]);
        $equity = $sum($sections[LedgerAccount::EQUITY]);

        return [
            'assets' => $sections[LedgerAccount::ASSET],
            'total_assets' => $assets,
            'liabilities' => $sections[LedgerAccount::LIABILITY],
            'total_liabilities' => $liabilities,
            'equity' => $sections[LedgerAccount::EQUITY],
            'total_equity' => $equity,
            'balanced' => abs($assets - $liabilities - $equity) < 0.005,
        ];
    }

    /** @return Collection<int, LedgerAccount> Cash, and every bank or wallet account that's been used */
    public function cashAccounts(): Collection
    {
        return $this->accounts()->filter(fn (LedgerAccount $account) => in_array(Chart::parentCode($account->code), self::CASH_ACCOUNTS, true))->values();
    }

    /**
     * Money in and out, oldest first, with a running balance.
     *
     * @return array{opening: float, closing: float, total_in: float, total_out: float, rows: array<int, array{date: string, description: string, account: string, in: float, out: float, balance: float}>}
     */
    public function cashBook(string $code, ?string $from, ?string $to): array
    {
        $accounts = $this->cashAccounts()->when($code !== '', fn ($all) => $all->where('code', $code));
        $names = $accounts->pluck('name', 'id');

        $lines = fn () => DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.shop_id', $this->shop->id)
            ->where('ledger_entries.epoch', $this->shop->ledger_epoch)
            ->whereIn('ledger_lines.ledger_account_id', $names->keys());

        $running = $from ? (int) $lines()->whereDate('ledger_entries.entry_date', '<', $from)->sum('ledger_lines.amount_paise') : 0;
        $opening = $running;
        $in = 0;
        $out = 0;
        $rows = [];

        $period = $lines()
            ->when($from, fn ($query) => $query->whereDate('ledger_entries.entry_date', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('ledger_entries.entry_date', '<=', $to))
            ->orderBy('ledger_entries.entry_date')
            ->orderBy('ledger_lines.id')
            ->get(['ledger_entries.entry_date', 'ledger_entries.description', 'ledger_lines.ledger_account_id', 'ledger_lines.amount_paise']);

        foreach ($period as $line) {
            $paise = (int) $line->amount_paise;
            $running += $paise;
            $paise > 0 ? $in += $paise : $out -= $paise;

            $rows[] = [
                'date' => substr((string) $line->entry_date, 0, 10),
                'description' => $line->description,
                'account' => $names[$line->ledger_account_id],
                'in' => max($paise, 0) / 100,
                'out' => max(-$paise, 0) / 100,
                'balance' => $running / 100,
            ];
        }

        return ['opening' => $opening / 100, 'closing' => $running / 100, 'total_in' => $in / 100, 'total_out' => $out / 100, 'rows' => $rows];
    }

    /**
     * One column per branch. A shop-wide cost, or anything with no branch, has its
     * own column rather than being shared out by a guess.
     *
     * @return array{columns: array<int, string>, rows: array<int, array{name: string, amounts: array<int, float>, emphasis: bool}>}
     */
    public function branchProfitAndLoss(?string $from, ?string $to): array
    {
        $branches = Branch::withoutGlobalScopes()->where('shop_id', $this->shop->id)->orderByDesc('is_main')->orderBy('name')->get();
        $statements = $branches->map(fn (Branch $branch) => $this->profitAndLoss($from, $to, $branch->id))->all();
        $statements[] = $this->profitAndLoss($from, $to, null, true);
        $statements[] = $this->profitAndLoss($from, $to);

        $columns = [...$branches->pluck('name')->all(), 'Not by Branch', 'Total'];
        $names = fn (string $section) => collect($statements)->flatMap(fn (array $statement) => array_column($statement[$section], 'name'))->unique()->values();
        $lookup = fn (string $section, string $name) => array_map(fn (array $statement) => (float) (collect($statement[$section])->firstWhere('name', $name)['amount'] ?? 0), $statements);
        $row = fn (string $name, array $amounts, bool $emphasis = false) => ['name' => $name, 'amounts' => $amounts, 'emphasis' => $emphasis];
        $rows = [];

        foreach ($names('income') as $name) {
            $rows[] = $row($name, $lookup('income', $name));
        }

        $rows[] = $row('Cost of Goods Sold', array_column($statements, 'cogs'));
        $rows[] = $row('Gross Profit', array_column($statements, 'gross_profit'), true);

        foreach ($names('expenses') as $name) {
            $rows[] = $row($name, $lookup('expenses', $name));
        }

        $rows[] = $row('Net Profit', array_column($statements, 'net_profit'), true);

        return ['columns' => $columns, 'rows' => $rows];
    }
}
