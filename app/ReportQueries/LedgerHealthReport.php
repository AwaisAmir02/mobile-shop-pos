<?php

namespace App\ReportQueries;

use App\Actions\OwnerMoney;
use App\Enums\RecordStatus;
use App\Enums\SaleStatus;
use App\Enums\UdhaarTransactionType;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerOpening;
use App\Models\LedgerBaseline;
use App\Models\LedgerOutbox;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/**
 * Proves the ledger against the numbers the shop already trusts. Records is what
 * today's screens say. Explained is a known way they differ, such as an
 * overpayment, which the screens floor at zero and the ledger keeps. What's left
 * should be nothing, or a few paise of unit-cost rounding on Inventory.
 */
class LedgerHealthReport
{
    public const INVENTORY_ROUNDING_TOLERANCE = 1.0;

    /** @var array<string, string> table => the column holding what's charged */
    protected const MODULES = ['wallet_loads' => 'total', 'balance_loads' => 'total', 'bill_payments' => 'total', 'sim_sales' => 'total', 'repairs' => 'total', 'nadra_verifications' => 'total', 'service_payments' => 'amount'];

    public function __construct(protected Shop $shop) {}

    /** @return array<int, array{label: string, ledger: float, records: float, explained: float, unexplained: float, note: string}> */
    public function checks(): array
    {
        return [
            $this->receivable(),
            $this->payable(),
            $this->inventory(),
            $this->netSales(),
            $this->employeeAdvances(),
            $this->withholdings(),
        ];
    }

    public function isHealthy(): bool
    {
        return collect($this->checks())->every(fn (array $check) => abs($check['unexplained']) <= ($check['tolerance'] ?? 0.0));
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $outbox = LedgerOutbox::where('shop_id', $this->shop->id);

        return [
            'waiting' => (clone $outbox)->count(),
            'failed' => (clone $outbox)->where('attempts', '>', 0)->count(),
            'lines_with_no_cost' => DB::table('sale_items')->where('shop_id', $this->shop->id)->whereNull('cost_price')->count(),
        ];
    }

    protected function ledgerBalance(string ...$codes): float
    {
        $paise = DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->where('ledger_entries.shop_id', $this->shop->id)
            ->where('ledger_entries.epoch', $this->shop->ledger_epoch)
            ->whereIn('ledger_accounts.code', $codes)
            ->sum('ledger_lines.amount_paise');

        return round((float) $paise / 100, 2);
    }

    protected function check(string $label, float $ledger, float $records, float $explained, string $note, float $tolerance = 0.0): array
    {
        return [
            'label' => $label,
            'ledger' => round($ledger, 2),
            'records' => round($records, 2),
            'explained' => round($explained, 2),
            'unexplained' => round($ledger - $records - $explained, 2),
            'note' => $note,
            'tolerance' => $tolerance,
        ];
    }

    /** @return array{0: float, 1: float} what the screens say is owed, and how far the plain sum differs */
    protected function moduleDues(): array
    {
        $owed = 0.0;
        $explained = 0.0;

        foreach (self::MODULES as $table => $totalColumn) {
            $rows = DB::table($table)->where('shop_id', $this->shop->id)->where('status', '!=', RecordStatus::Cancelled->value)
                ->when($table === 'wallet_loads', fn ($query) => $query->where('direction', 'cash_in'))
                ->get([$totalColumn.' as total', 'amount_paid', 'payment_status']);

            foreach ($rows as $row) {
                $screens = $row->payment_status === 'paid' ? 0.0 : max(0.0, (float) $row->total - (float) $row->amount_paid);
                $owed += $screens;
                $explained += (float) $row->total - (float) $row->amount_paid - $screens;
            }
        }

        return [$owed, $explained];
    }

    protected function receivable(): array
    {
        $paidBySale = DB::table('sale_payments')->where('shop_id', $this->shop->id)->groupBy('sale_id')->selectRaw('sale_id, SUM(amount) as paid')->pluck('paid', 'sale_id');
        $owed = 0.0;
        $overpaid = 0.0;

        foreach (DB::table('sales')->where('shop_id', $this->shop->id)->where('status', '!=', SaleStatus::Cancelled->value)->get(['id', 'total']) as $sale) {
            $balance = (float) $sale->total - (float) ($paidBySale[$sale->id] ?? 0);
            $balance > 0 ? $owed += $balance : $overpaid += $balance;
        }

        $udhaar = DB::table('udhaar_transactions')->where('shop_id', $this->shop->id)->where('status', '!=', RecordStatus::Cancelled->value)->get(['type', 'amount'])
            ->sum(fn ($row) => in_array($row->type, [UdhaarTransactionType::Given->value, UdhaarTransactionType::RepaymentReversal->value], true) ? (float) $row->amount : -(float) $row->amount);

        $manual = (float) DB::table('party_ledger_entries')->where('shop_id', $this->shop->id)->whereNotNull('customer_id')->where('status', '!=', RecordStatus::Cancelled->value)
            ->selectRaw("SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as net")->value('net');

        [$moduleOwed, $moduleExplained] = $this->moduleDues();

        return $this->check('Customers owe', $this->ledgerBalance(Chart::RECEIVABLE), $owed + $udhaar + $manual + $moduleOwed, $overpaid + $moduleExplained, 'Sales, counter services, udhaar and party ledger balances. Overpayments count against the customer here.');
    }

    protected function payable(): array
    {
        $paidByRow = DB::table('stock_in_payments')->where('shop_id', $this->shop->id)->groupBy('stock_in_id')->selectRaw('stock_in_id, SUM(amount) as paid')->pluck('paid', 'stock_in_id');
        $owed = 0.0;
        $explained = 0.0;

        foreach (DB::table('stock_ins')->where('shop_id', $this->shop->id)->where('status', '!=', RecordStatus::Cancelled->value)->get(['id', 'total_cost', 'amount_paid', 'payment_status']) as $row) {
            $screens = $row->payment_status === 'paid' ? 0.0 : max(0.0, (float) ($row->total_cost ?? 0) - (float) $row->amount_paid);
            $ledger = (float) ($row->total_cost ?? 0) - (float) ($paidByRow[$row->id] ?? 0);
            $owed += $screens;
            $explained += $ledger - $screens;
        }

        $manual = (float) DB::table('party_ledger_entries')->where('shop_id', $this->shop->id)->whereNotNull('vendor_id')->where('status', '!=', RecordStatus::Cancelled->value)
            ->selectRaw("SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END) as net")->value('net');

        return $this->check('Vendors are owed', -$this->ledgerBalance(Chart::PAYABLE), $owed + $manual, $explained, 'Stock In balances and party ledger entries. Overpayments and batches with no cost are floored at zero on the screens.');
    }

    protected function inventory(): array
    {
        return $this->check('Inventory at cost', $this->ledgerBalance(Chart::INVENTORY), LedgerOpening::inventoryValue($this->shop->id), 0.0, 'Batches at their cost, and stock no batch covers at the product\'s cost. Rounding a billed total into a unit cost can leave a few paise.', self::INVENTORY_ROUNDING_TOLERANCE);
    }

    protected function employeeAdvances(): array
    {
        $balance = DB::table('employee_advances')->where('shop_id', $this->shop->id)->where('status', '!=', RecordStatus::Cancelled->value)
            ->selectRaw("SUM(CASE WHEN type = 'given' THEN amount ELSE -amount END) as net")->value('net');

        return $this->check('Employee advances owed', $this->ledgerBalance(Chart::EMPLOYEE_ADVANCES), (float) $balance, 0.0, 'What staff still owe on the Advances screen.');
    }

    protected function withholdings(): array
    {
        $held = OwnerMoney::withheldBalance($this->shop->id);

        return $this->check('Salary amounts held', -$this->ledgerBalance(Chart::WITHHOLDINGS), $held, 0.0, 'Held for someone else on paid payslips, less what has been paid over.');
    }

    protected function netSales(): array
    {
        $startDate = $this->shop->ledger_started_at->toDateString();
        $total = (float) DB::table('sales')->where('shop_id', $this->shop->id)->where('status', '!=', SaleStatus::Cancelled->value)->whereDate('created_at', '>=', $startDate)->sum('total');

        // Sales made earlier on the start day are in the opening position, not posted.
        $alreadyInOpening = LedgerBaseline::where('shop_id', $this->shop->id)->where('epoch', $this->shop->ledger_epoch)->where('source_type', 'sale')
            ->whereIn('source_id', DB::table('sales')->where('shop_id', $this->shop->id)->whereDate('created_at', '>=', $startDate)->select('id'))
            ->get()
            ->sum(fn (LedgerBaseline $baseline) => collect($baseline->lines)->whereIn('account', [Chart::SALES, Chart::DISCOUNTS])->sum('paise'));

        return $this->check('Sales since the start, after discounts', -$this->ledgerBalance(Chart::SALES, Chart::DISCOUNTS), $total + $alreadyInOpening / 100, 0.0, 'Excludes changes to older sales, which go to Prior Period.');
    }
}
