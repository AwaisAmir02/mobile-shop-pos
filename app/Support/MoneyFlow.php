<?php

namespace App\Support;

use App\Enums\WalletLoadDirection;
use App\Models\Branch;
use App\Models\MoneyEntry;
use App\Models\ShopAccount;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cash & Balances: every movement of money through the shop's cash and its
 * accounts, worked out from the records that already hold it. Read-only:
 * it changes no total, due or posting anywhere else.
 *
 * Buckets: "cash:{branch}" (cash in hand at a branch), "account:{id}" (a shop
 * account: bank, JazzCash, Easypaisa, a cash drawer) and "unrecorded" (money
 * that moved but nobody said whether it was cash or an account; it's shown,
 * never added to a balance).
 *
 * Sources and their sign (+ money in, − money out):
 * - Sales: each sale payment, on its date. Cancelled sales are left out.
 * - Udhaar: Given −, Repayment +, reversals the other way; only when cash
 *   or an account was picked (a Given can be goods, not money).
 * - Counter services (repair, bill, wallet/balance load, SIM, NADRA, service):
 *   each dated customer payment; any older paid amount without one counts on
 *   the record's day (a Wallet Load's way is known, the rest is not recorded).
 * - Wallet Load: Cash In sends the amount out of its account; Cash Out hands
 *   the amount out of cash. Bills: the bill amount leaves the "Send From" account.
 * - Vendor payments (Stock In), Expenses (salary and advance write-off lines
 *   excluded: payroll counts once, as the payslip), Payslips (net pay),
 *   employee advances given/repaid, owner withdrawals, withholding payments.
 * - MoneyEntry: opening balances and count corrections ('entry', left out of
 *   in/out totals), and money moved between buckets ('move').
 */
class MoneyFlow
{
    /** Record types paid like counter services: document key => [table, total column]. */
    public const COUNTER = [
        'wallet_load' => ['wallet_loads', 'total', 'Wallet Load', 'WL-'],
        'balance_load' => ['balance_loads', 'total', 'Balance Load', 'BL-'],
        'bill_payment' => ['bill_payments', 'total', 'Bill', 'BP-'],
        'sim_sale' => ['sim_sales', 'total', 'SIM Sale', 'SS-'],
        'repair' => ['repairs', 'total', 'Repair', 'RP-'],
        'nadra_verification' => ['nadra_verifications', 'total', 'NADRA Verification', 'NV-'],
        'service_payment' => ['service_payments', 'amount', 'Service', 'SV-'],
    ];

    public const SOURCES = [
        'sale' => 'Sales',
        'udhaar' => 'Udhaar',
        'counter' => 'Counter services',
        'wallet' => 'Wallet Loads',
        'bill' => 'Bills paid',
        'vendor' => 'Vendor payments',
        'expense' => 'Expenses',
        'payroll' => 'Payroll',
        'owner' => 'Owner money',
        'move' => 'Money moved',
        'entry' => 'Openings & count corrections',
    ];

    private ?int $mainBranchId = null;

    private ?array $buckets = null;

    public function __construct(private readonly int $shopId) {}

    /** @return array<string, array{key: string, kind: string, label: string, sub: string, branch_id: ?int, account_id: ?int}> */
    public function buckets(): array
    {
        if ($this->buckets !== null) {
            return $this->buckets;
        }

        $branches = Branch::withoutGlobalScopes()->where('shop_id', $this->shopId)->orderByDesc('is_main')->orderBy('name')->get();
        $multi = $branches->count() > 1;
        $buckets = [];

        foreach ($branches as $branch) {
            $buckets['cash:'.$branch->id] = [
                'key' => 'cash:'.$branch->id,
                'kind' => 'cash',
                'label' => $multi ? 'Cash · '.$branch->name : 'Cash in hand',
                'sub' => 'Cash',
                'branch_id' => $branch->id,
                'account_id' => null,
            ];
        }

        foreach (ShopAccount::withoutGlobalScopes()->where('shop_id', $this->shopId)->with('branch')->orderBy('name')->get() as $account) {
            $buckets['account:'.$account->id] = [
                'key' => 'account:'.$account->id,
                'kind' => 'account',
                'label' => $account->name,
                'sub' => $account->branch ? $account->branch->name.' cash drawer' : $account->provider_type,
                'branch_id' => $account->branch_id,
                'account_id' => $account->id,
            ];
        }

        return $this->buckets = $buckets;
    }

    /**
     * Every movement between two local dates (inclusive), oldest first.
     * Each: date (Y-m-d), bucket, amount (signed), source, label, link [route, param]|null.
     */
    public function movements(?string $from = null, ?string $to = null): Collection
    {
        $rows = collect()
            ->concat($this->sales($from, $to))
            ->concat($this->udhaar($from, $to))
            ->concat($this->counterPayments($from, $to))
            ->concat($this->walletPrincipal($from, $to))
            ->concat($this->billPrincipal($from, $to))
            ->concat($this->vendorPayments($from, $to))
            ->concat($this->expenses($from, $to))
            ->concat($this->payroll($from, $to))
            ->concat($this->ownerMoney($from, $to))
            ->concat($this->entries($from, $to));

        return $rows
            ->filter(fn (array $row) => round($row['amount'], 2) != 0.0)
            ->sortBy(fn (array $row) => $row['date'].'|'.str_pad((string) $row['order'], 12, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Balances on a day's close (today if null). For each bucket: the opening
     * entry (if any), the balance, and the last count. With an opening
     * balance, only movements from its date on count; without one, everything.
     *
     * @return Collection<string, array>
     */
    public function balances(?string $asOf = null): Collection
    {
        $asOf ??= self::today();
        $openings = $this->openings();
        $movements = $this->movements(null, $asOf)->groupBy('bucket');
        $counts = MoneyEntry::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('kind', MoneyEntry::COUNT)
            ->where('status', '!=', 'cancelled')->orderByDesc('entry_date')->orderByDesc('id')->get()
            ->groupBy(fn (MoneyEntry $entry) => $entry->toBucket());

        return collect($this->buckets())->map(function (array $bucket) use ($openings, $movements, $counts) {
            $opening = $openings->get($bucket['key']);
            $rows = collect($movements->get($bucket['key'], []))
                ->when($opening, fn (Collection $rows) => $rows->filter(fn (array $row) => $row['date'] >= $opening->entry_date->toDateString()));

            return $bucket + [
                'opening' => $opening,
                'balance' => round($rows->sum('amount'), 2),
                'in' => round($rows->where('amount', '>', 0)->where('source', '!=', 'entry')->sum('amount'), 2),
                'out' => round(-$rows->where('amount', '<', 0)->where('source', '!=', 'entry')->sum('amount'), 2),
                'last_count' => $counts->get($bucket['key'])?->first(),
            ];
        });
    }

    /** One bucket's balance at the close of a day; what a count is checked against. */
    public function balanceOf(string $bucket, string $asOf): float
    {
        return (float) ($this->balances($asOf)->get($bucket)['balance'] ?? 0.0);
    }

    /** @return Collection<string, MoneyEntry> the active opening entry per bucket */
    public function openings(): Collection
    {
        return MoneyEntry::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('kind', MoneyEntry::OPENING)
            ->where('status', '!=', 'cancelled')->orderBy('id')->get()
            ->keyBy(fn (MoneyEntry $entry) => $entry->toBucket());
    }

    /**
     * Per day, per bucket: money in, money out, net, and by source. Plus the
     * fees and commission earned on counter services that day.
     *
     * @return Collection<string, array{date: string, buckets: Collection, fees: float, unrecorded_in: float, unrecorded_out: float}>
     */
    public function daily(string $from, string $to): Collection
    {
        $fees = $this->feesByDay($from, $to);

        return $this->movements($from, $to)
            ->groupBy('date')
            ->map(function (Collection $rows, string $date) use ($fees) {
                $byBucket = $rows->groupBy('bucket')->map(fn (Collection $bucketRows, string $bucket) => [
                    'bucket' => $bucket,
                    'in' => round($bucketRows->where('amount', '>', 0)->sum('amount'), 2),
                    'out' => round(-$bucketRows->where('amount', '<', 0)->sum('amount'), 2),
                    'net' => round($bucketRows->sum('amount'), 2),
                    'sources' => $bucketRows->groupBy('source')->map(fn (Collection $sourceRows) => [
                        'in' => round($sourceRows->where('amount', '>', 0)->sum('amount'), 2),
                        'out' => round(-$sourceRows->where('amount', '<', 0)->sum('amount'), 2),
                    ]),
                ]);

                return [
                    'date' => $date,
                    'buckets' => $byBucket,
                    'rows' => $rows,
                    'fees' => $fees[$date] ?? 0.0,
                ];
            })
            ->union(collect($fees)->keys()->mapWithKeys(fn (string $date) => [$date => ['date' => $date, 'buckets' => collect(), 'rows' => collect(), 'fees' => $fees[$date]]]))
            ->sortKeysDesc();
    }

    /** Fees and commission (fee − discount) on the counter services that have one, by day. */
    public function feesByDay(string $from, string $to): array
    {
        $fees = [];

        foreach (['wallet_loads', 'bill_payments', 'balance_loads', 'sim_sales', 'nadra_verifications'] as $table) {
            DB::table($table)
                ->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled')
                ->whereBetween(DB::raw($this->localDate('created_at')), [$from, $to])
                ->selectRaw($this->localDate('created_at').' as day, SUM(fee) - SUM(discount) as earned')
                ->groupBy('day')
                ->get()
                ->each(function ($row) use (&$fees) {
                    $fees[$row->day] = round(($fees[$row->day] ?? 0.0) + (float) $row->earned, 2);
                });
        }

        return $fees;
    }

    public static function today(): string
    {
        return CarbonImmutable::now(Entitlements::TIMEZONE)->toDateString();
    }

    // ---------------------------------------------------------------- sources

    private function sales(?string $from, ?string $to): array
    {
        return DB::table('sale_payments')
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sale_payments.shop_id', $this->shopId)
            ->where('sales.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->where('sale_payments.payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('sale_payments.payment_date', '<=', $to))
            ->get(['sale_payments.id', 'sale_payments.amount', 'sale_payments.payment_date', 'sale_payments.payment_method', 'sale_payments.shop_account_id', 'sales.id as sale_id', 'sales.branch_id'])
            ->map(fn ($row) => $this->row(
                $this->dateOf($row->payment_date),
                $this->bucketFor($row->payment_method, $row->shop_account_id, $row->branch_id),
                (float) $row->amount,
                'sale',
                'Sale INV-'.str_pad((string) $row->sale_id, 6, '0', STR_PAD_LEFT),
                ['sales.show', $row->sale_id],
                $row->id,
            ))->all();
    }

    private function udhaar(?string $from, ?string $to): array
    {
        return DB::table('udhaar_transactions')
            ->join('customers', 'customers.id', '=', 'udhaar_transactions.customer_id')
            ->where('udhaar_transactions.shop_id', $this->shopId)
            ->where('udhaar_transactions.status', '!=', 'cancelled')
            ->whereNotNull('udhaar_transactions.payment_method')
            ->when($from, fn ($q) => $q->where('udhaar_transactions.transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('udhaar_transactions.transaction_date', '<=', $to))
            ->get(['udhaar_transactions.*', 'customers.name as customer_name'])
            ->map(function ($row) {
                // Given and a reversed repayment send money out; a repayment and a reversed given bring it in.
                $sign = in_array($row->type, ['given', 'repayment_reversal'], true) ? -1 : 1;
                $what = match ($row->type) {
                    'given' => 'Udhaar given',
                    'repayment' => 'Udhaar repaid',
                    default => 'Udhaar correction',
                };

                return $this->row(
                    $this->dateOf($row->transaction_date),
                    $this->bucketFor($row->payment_method, $row->shop_account_id, $row->branch_id),
                    $sign * (float) $row->amount,
                    'udhaar',
                    $what.': '.$row->customer_name,
                    ['udhaar.show', $row->customer_id],
                    $row->id,
                );
            })->all();
    }

    /** Customer payments on counter services: dated rows, plus any older paid amount without one. */
    private function counterPayments(?string $from, ?string $to): array
    {
        $rows = [];

        foreach (self::COUNTER as $key => [$table, $totalColumn, $label, $prefix]) {
            $documents = DB::table($table)->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled');

            DB::table('counter_service_payments')
                ->joinSub($documents->clone()->select(['id', 'branch_id']), 'doc', 'doc.id', '=', 'counter_service_payments.source_id')
                ->where('counter_service_payments.shop_id', $this->shopId)
                ->where('counter_service_payments.source_type', $key)
                ->when($from, fn ($q) => $q->where('counter_service_payments.paid_on', '>=', $from))
                ->when($to, fn ($q) => $q->where('counter_service_payments.paid_on', '<=', $to))
                ->get(['counter_service_payments.*', 'doc.branch_id as doc_branch_id'])
                ->each(function ($payment) use (&$rows, $label, $prefix) {
                    $rows[] = $this->row(
                        $this->dateOf($payment->paid_on),
                        $this->bucketFor($payment->payment_method, $payment->shop_account_id, $payment->doc_branch_id),
                        (float) $payment->amount,
                        'counter',
                        $label.' '.$prefix.str_pad((string) $payment->source_id, 6, '0', STR_PAD_LEFT).' paid',
                        null,
                        $payment->id,
                    );
                });

            // Paid before dated payments were kept (or marked paid in a way that left none): on the record's own day.
            $recorded = DB::table('counter_service_payments')->where('source_type', $key)
                ->selectRaw('source_id, SUM(amount) as paid')->groupBy('source_id');

            $documents->clone()
                ->leftJoinSub($recorded, 'csp', 'csp.source_id', '=', $table.'.id')
                ->whereRaw('amount_paid - COALESCE(csp.paid, 0) <> 0')
                ->when($from, fn ($q) => $q->where(DB::raw($this->localDate($table.'.created_at')), '>=', $from))
                ->when($to, fn ($q) => $q->where(DB::raw($this->localDate($table.'.created_at')), '<=', $to))
                ->get([$table.'.*', DB::raw('COALESCE(csp.paid, 0) as recorded_paid'), DB::raw($this->localDate($table.'.created_at').' as local_day')])
                ->each(function ($document) use (&$rows, $key, $label, $prefix) {
                    [$method, $account] = $key === 'wallet_load'
                        ? ($document->direction === WalletLoadDirection::CashIn->value ? ['cash', null] : ['account', $document->shop_account_id])
                        : [null, null];

                    $rows[] = $this->row(
                        $document->local_day,
                        $this->bucketFor($method, $account, $document->branch_id),
                        (float) $document->amount_paid - (float) $document->recorded_paid,
                        'counter',
                        $label.' '.$prefix.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT).' paid',
                        null,
                        $document->id,
                    );
                });
        }

        return $rows;
    }

    /** What the shop itself sends on a Wallet Load: from its account (Cash In) or out of cash (Cash Out). */
    private function walletPrincipal(?string $from, ?string $to): array
    {
        return $this->byCreatedDay(DB::table('wallet_loads')->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled'), 'wallet_loads', $from, $to)
            ->map(function ($load) {
                $cashIn = $load->direction === WalletLoadDirection::CashIn->value;

                return $this->row(
                    $load->local_day,
                    $cashIn ? $this->bucketFor('account', $load->shop_account_id, $load->branch_id) : $this->bucketFor('cash', null, $load->branch_id),
                    -(float) $load->net_amount,
                    'wallet',
                    ($cashIn ? 'Wallet Cash In WL-' : 'Wallet Cash Out WL-').str_pad((string) $load->id, 6, '0', STR_PAD_LEFT).($cashIn ? ' sent' : ' cash handed over'),
                    null,
                    $load->id,
                );
            })->all();
    }

    private function billPrincipal(?string $from, ?string $to): array
    {
        return $this->byCreatedDay(DB::table('bill_payments')->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled')->whereNotNull('shop_account_id'), 'bill_payments', $from, $to)
            ->map(fn ($bill) => $this->row(
                $bill->local_day,
                $this->bucketFor('account', $bill->shop_account_id, $bill->branch_id),
                -(float) $bill->amount,
                'bill',
                'Bill BP-'.str_pad((string) $bill->id, 6, '0', STR_PAD_LEFT).' paid from account',
                null,
                $bill->id,
            ))->all();
    }

    private function vendorPayments(?string $from, ?string $to): array
    {
        return DB::table('stock_in_payments')
            ->join('stock_ins', 'stock_ins.id', '=', 'stock_in_payments.stock_in_id')
            ->leftJoin('vendors', 'vendors.id', '=', 'stock_ins.vendor_id')
            ->where('stock_in_payments.shop_id', $this->shopId)
            ->where('stock_ins.status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->where('stock_in_payments.payment_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('stock_in_payments.payment_date', '<=', $to))
            ->get(['stock_in_payments.*', 'stock_ins.branch_id', 'vendors.name as vendor_name'])
            ->map(fn ($row) => $this->row(
                $this->dateOf($row->payment_date),
                $this->bucketFor($row->payment_method, $row->shop_account_id, $row->branch_id),
                -(float) $row->amount,
                'vendor',
                'Paid '.($row->vendor_name ?? 'for stock'),
                null,
                $row->id,
            ))->all();
    }

    private function expenses(?string $from, ?string $to): array
    {
        return DB::table('expenses')
            ->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled')
            ->whereNull('payslip_id')->whereNull('employee_advance_id')
            ->when($from, fn ($q) => $q->where('expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('expense_date', '<=', $to))
            ->get()
            ->map(fn ($row) => $this->row(
                $this->dateOf($row->expense_date),
                $this->bucketFor($row->payment_method, $row->shop_account_id, $row->branch_id),
                -(float) $row->amount,
                'expense',
                'Expense: '.$row->description,
                ['expenses.index', null],
                $row->id,
            ))->all();
    }

    /** Net pay leaves cash or the chosen account; advances go out and come back. Cash is assumed when no account was picked, as Payroll does. */
    private function payroll(?string $from, ?string $to): array
    {
        $rows = DB::table('payslips')
            ->where('shop_id', $this->shopId)->where('status', 'paid')
            ->when($from, fn ($q) => $q->where('paid_on', '>=', $from))
            ->when($to, fn ($q) => $q->where('paid_on', '<=', $to))
            ->get()
            ->map(fn ($row) => $this->row(
                $this->dateOf($row->paid_on),
                $this->bucketFor($row->shop_account_id ? 'account' : 'cash', $row->shop_account_id, $row->branch_id),
                -(float) $row->net_pay,
                'payroll',
                'Salary: '.$row->employee_name,
                null,
                $row->id,
            ))->all();

        $advances = DB::table('employee_advances')
            ->join('employees', 'employees.id', '=', 'employee_advances.employee_id')
            ->where('employee_advances.shop_id', $this->shopId)->where('employee_advances.status', '!=', 'cancelled')
            ->whereIn('employee_advances.type', ['given', 'repaid'])
            ->when($from, fn ($q) => $q->where('employee_advances.transaction_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('employee_advances.transaction_date', '<=', $to))
            ->get(['employee_advances.*', 'employees.name as employee_name', 'employees.branch_id as employee_branch_id'])
            ->map(fn ($row) => $this->row(
                $this->dateOf($row->transaction_date),
                $this->bucketFor($row->shop_account_id ? 'account' : 'cash', $row->shop_account_id, $row->employee_branch_id),
                ($row->type === 'given' ? -1 : 1) * (float) $row->amount,
                'payroll',
                ($row->type === 'given' ? 'Advance to ' : 'Advance repaid by ').$row->employee_name,
                null,
                $row->id,
            ))->all();

        return array_merge($rows, $advances);
    }

    private function ownerMoney(?string $from, ?string $to): array
    {
        $rows = [];

        foreach (['owner_withdrawals' => 'Owner took out', 'withholding_payments' => 'Withheld salary paid over'] as $table => $label) {
            DB::table($table)
                ->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled')
                ->when($from, fn ($q) => $q->where('paid_on', '>=', $from))
                ->when($to, fn ($q) => $q->where('paid_on', '<=', $to))
                ->get()
                ->each(function ($row) use (&$rows, $label) {
                    $rows[] = $this->row(
                        $this->dateOf($row->paid_on),
                        $this->bucketFor($row->payment_method, $row->shop_account_id, null),
                        -(float) $row->amount,
                        'owner',
                        $label.($row->note ? ': '.$row->note : ''),
                        null,
                        $row->id,
                    );
                });
        }

        return $rows;
    }

    private function entries(?string $from, ?string $to): array
    {
        $rows = [];

        MoneyEntry::withoutGlobalScopes()->where('shop_id', $this->shopId)->where('status', '!=', 'cancelled')
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->get()
            ->each(function (MoneyEntry $entry) use (&$rows) {
                $date = $entry->entry_date->toDateString();
                $note = $entry->note ? ': '.$entry->note : '';

                if ($entry->kind === MoneyEntry::TRANSFER) {
                    $rows[] = $this->row($date, $entry->fromBucket() ?? 'unrecorded', -(float) $entry->amount, 'move', 'Money moved out'.$note, null, $entry->id);
                    $rows[] = $this->row($date, $entry->toBucket() ?? 'unrecorded', (float) $entry->amount, 'move', 'Money moved in'.$note, null, $entry->id);

                    return;
                }

                $rows[] = $this->row(
                    $date,
                    $entry->toBucket() ?? 'unrecorded',
                    (float) $entry->amount,
                    'entry',
                    ($entry->kind === MoneyEntry::OPENING ? 'Opening balance' : 'Count correction').$note,
                    null,
                    // Openings sort first on their day, counts last.
                    $entry->kind === MoneyEntry::OPENING ? 0 : PHP_INT_MAX - $entry->id,
                );
            });

        return $rows;
    }

    // ---------------------------------------------------------------- helpers

    private function byCreatedDay($query, string $table, ?string $from, ?string $to): Collection
    {
        return $query
            ->when($from, fn ($q) => $q->where(DB::raw($this->localDate($table.'.created_at')), '>=', $from))
            ->when($to, fn ($q) => $q->where(DB::raw($this->localDate($table.'.created_at')), '<=', $to))
            ->get([$table.'.*', DB::raw($this->localDate($table.'.created_at').' as local_day')]);
    }

    /** created_at is stored in UTC; the shop's day is Pakistan time. */
    private function localDate(string $column): string
    {
        $offset = CarbonImmutable::now(Entitlements::TIMEZONE)->utcOffset();

        return "DATE(DATE_ADD({$column}, INTERVAL {$offset} MINUTE))";
    }

    private function dateOf($value): string
    {
        return substr((string) $value, 0, 10);
    }

    private function bucketFor(?string $method, ?int $shopAccountId, ?int $branchId): string
    {
        return MoneyEntry::bucketKey($method, $shopAccountId, $branchId ?? $this->mainBranchId()) ?? 'unrecorded';
    }

    private function mainBranchId(): int
    {
        return $this->mainBranchId ??= BranchContext::current()->mainBranchIdFor($this->shopId);
    }

    private function row(string $date, string $bucket, float $amount, string $source, string $label, ?array $link, int $order): array
    {
        return ['date' => $date, 'bucket' => $bucket, 'amount' => round($amount, 2), 'source' => $source, 'label' => $label, 'link' => $link, 'order' => $order];
    }
}
