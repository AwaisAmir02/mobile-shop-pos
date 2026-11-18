<?php

namespace App\Services\LegacyImport;

use App\Models\Customer;
use App\Models\Vendor;
use App\Support\ApiShopContext;
use App\Support\CustomerDuesStatement;
use App\Support\Quantity;
use App\Support\StockInPaymentBackfill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only. totals() works on plain rows, so the old shop (from the
 * legacy:extract file) and the imported one (from the database) go through
 * the very same sums. checks() asks the app's own code for dues, balances
 * and stock and compares each with a plain SQL sum.
 */
class LegacyShopAudit
{
    public const TABLES = ['products', 'customers', 'vendors', 'sales', 'sale_items', 'sale_payments', 'stock_ins', 'expenses', 'udhaar_transactions', 'repairs', 'sale_revisions', 'module_revisions'];

    public function __construct(protected int $shopId) {}

    /** @return array<string, array<int, array<string, mixed>>> */
    public function tables(): array
    {
        $tables = [];

        foreach ([...self::TABLES, 'stock_in_payments'] as $table) {
            $tables[$table] = DB::table($table)->where('shop_id', $this->shopId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $tables;
    }

    /**
     * @param  array<string, array<int, array<string, mixed>>>  $t
     * @return array<string, string> label => value, money to 2 places
     */
    public static function totals(array $t): array
    {
        $money = fn ($v) => number_format(round((float) $v, 2), 2, '.', '');
        $active = fn (string $table) => collect($t[$table])->where('status', '!=', 'cancelled');

        $sales = collect($t['sales']);
        $completedIds = $sales->where('status', 'completed')->pluck('id')->flip();
        $cancelledIds = $sales->where('status', 'cancelled')->pluck('id')->flip();
        $paidBySale = collect($t['sale_payments'])->groupBy('sale_id')->map(fn ($rows) => $rows->sum(fn ($r) => (float) $r['amount']));
        $completedLines = collect($t['sale_items'])->filter(fn ($i) => $completedIds->has($i['sale_id']));
        $lineCost = $completedLines->sum(fn ($i) => $i['cost_price'] === null ? 0 : round((float) $i['cost_price'] * (float) $i['quantity'], 2));
        $completedTotal = $sales->where('status', 'completed')->sum(fn ($s) => (float) $s['total']);

        $udhaar = $active('udhaar_transactions');
        $udhaarByType = fn (string $type) => $udhaar->where('type', $type)->sum(fn ($r) => (float) $r['amount']);
        $udhaarNet = $udhaarByType('given') + $udhaarByType('repayment_reversal') - $udhaarByType('repayment') - $udhaarByType('given_reversal');

        $stockIns = $active('stock_ins');
        $owed = fn ($r) => $r['payment_status'] === 'paid' ? 0.0 : max(0.0, (float) ($r['total_cost'] ?? 0) - (float) $r['amount_paid']);
        $vendorNames = collect($t['vendors'])->pluck('name', 'id');
        $customerNames = collect($t['customers'])->pluck('name', 'id');
        $repairs = $active('repairs');
        $repairOwed = fn ($r) => $r['payment_status'] === 'paid' ? 0.0 : max(0.0, (float) $r['total'] - (float) $r['amount_paid']);

        $totals = [
            'Products' => (string) count($t['products']),
            'Units in stock' => (string) Quantity::fromThousandths(collect($t['products'])->sum(fn ($p) => Quantity::toThousandths($p['stock_quantity']))),
            'Customers' => (string) count($t['customers']),
            'Vendors' => (string) count($t['vendors']),
            'Sales, completed: count' => (string) $completedIds->count(),
            'Sales, completed: total' => $money($completedTotal),
            'Sales, completed: paid' => $money($completedIds->keys()->sum(fn ($id) => $paidBySale[$id] ?? 0)),
            'Sales, completed: lines' => (string) $completedLines->count(),
            'Sales, completed: line cost' => $money($lineCost),
            'Sales, completed: gross profit (total - line cost)' => $money($completedTotal - $lineCost),
            'Sales, cancelled: count' => (string) $cancelledIds->count(),
            'Sales, cancelled: total' => $money($sales->where('status', 'cancelled')->sum(fn ($s) => (float) $s['total'])),
            'Sales, cancelled: payments recorded' => $money($cancelledIds->keys()->sum(fn ($id) => $paidBySale[$id] ?? 0)),
            'Expenses: count' => (string) $active('expenses')->count(),
            'Expenses: amount' => $money($active('expenses')->sum(fn ($r) => (float) $r['amount'])),
            'Repairs: count' => (string) $repairs->count(),
            'Repairs: total' => $money($repairs->sum(fn ($r) => (float) $r['total'])),
            'Repairs: paid' => $money($repairs->sum(fn ($r) => (float) $r['amount_paid'])),
            'Udhaar: rows' => (string) $udhaar->count(),
            'Udhaar: given' => $money($udhaarByType('given')),
            'Udhaar: repayments' => $money($udhaarByType('repayment')),
            'Udhaar: repayment reversals' => $money($udhaarByType('repayment_reversal')),
            'Udhaar: net balance' => $money($udhaarNet),
            'Stock-ins: count' => (string) $stockIns->count(),
            'Stock-ins: units' => (string) Quantity::fromThousandths($stockIns->sum(fn ($r) => Quantity::toThousandths($r['quantity']))),
            'Stock-ins: units still in batches' => (string) Quantity::fromThousandths($stockIns->sum(fn ($r) => Quantity::toThousandths($r['quantity_remaining']))),
            'Stock-ins: cost' => $money($stockIns->sum(fn ($r) => (float) ($r['total_cost'] ?? 0))),
            'Stock-ins: amount paid' => $money($stockIns->sum(fn ($r) => (float) $r['amount_paid'])),
            'Vendor dues: total' => $money($stockIns->sum($owed)),
        ];

        foreach ($stockIns->groupBy('vendor_id') as $vendorId => $rows) {
            if (($due = $rows->sum($owed)) > 0) {
                $totals['Vendor dues: '.($vendorNames[$vendorId] ?? 'no vendor')] = $money($due);
            }
        }

        foreach ($customerNames as $customerId => $name) {
            $salesDue = $sales->where('status', 'completed')->where('customer_id', $customerId)->sum(fn ($s) => max(0.0, (float) $s['total'] - ($paidBySale[$s['id']] ?? 0)));
            $own = $udhaar->where('customer_id', $customerId);
            $udhaarDue = $own->whereIn('type', ['given', 'repayment_reversal'])->sum(fn ($r) => (float) $r['amount']) - $own->whereIn('type', ['repayment', 'given_reversal'])->sum(fn ($r) => (float) $r['amount']);
            $totals['Customer dues: '.trim((string) $name)] = $money($salesDue + $udhaarDue + $repairs->where('customer_id', $customerId)->sum($repairOwed));
        }

        $totals['Sale revisions'] = (string) count($t['sale_revisions']);
        $totals['Stock-in revisions'] = (string) count($t['module_revisions']);

        return $totals;
    }

    /** @return array<int, array{0: string, 1: bool, 2: string}> check, passed, detail */
    /** @param ?array<string, int> $booksBefore the books' row counts before the import; null when there's nothing to compare with */
    public function checks(?array $booksBefore = null): array
    {
        return ApiShopContext::current()->run($this->shopId, fn () => [
            $this->stockVersusBatches(),
            $this->stockInPaymentRows(),
            $this->vendorDues(),
            ...$this->customerBalances(),
            $this->saleArithmetic(),
            $this->booksUntouched($booksBefore),
        ]);
    }

    protected function stockVersusBatches(): array
    {
        $batches = DB::table('stock_ins')->where('shop_id', $this->shopId)->where('status', 'active')
            ->selectRaw('product_id, SUM(quantity_remaining) AS remaining')->groupBy('product_id')->pluck('remaining', 'product_id');
        $over = [];
        $legacyProducts = 0;
        $legacyUnits = 0;

        foreach (DB::table('products')->where('shop_id', $this->shopId)->get(['id', 'name', 'stock_quantity']) as $product) {
            $inBatches = Quantity::toThousandths($batches[$product->id] ?? 0);
            $stock = Quantity::toThousandths($product->stock_quantity);

            if ($inBatches > $stock) {
                $over[] = "{$product->name}: batches {$batches[$product->id]} > stock {$product->stock_quantity}";
            } elseif ($stock > $inBatches) {
                $legacyProducts++;
                $legacyUnits += $stock - $inBatches;
            }
        }

        $orphans = (string) ($batches[''] ?? 0);

        return ['Stock vs batches (batches never exceed stock)', $over === [],
            $over === [] ? "{$legacyProducts} products hold ".Quantity::fromThousandths($legacyUnits).' units from before batches; batches with no product hold '.$orphans.' units'
                : implode('; ', $over)];
    }

    protected function stockInPaymentRows(): array
    {
        $rows = DB::select('SELECT * FROM ('.StockInPaymentBackfill::RECONCILE_SQL.') r WHERE r.shop_id = ?', [$this->shopId]);
        $sum = (float) DB::table('stock_in_payments')->where('shop_id', $this->shopId)->sum('amount');
        $paid = (float) DB::table('stock_ins')->where('shop_id', $this->shopId)->sum('amount_paid');

        return ['Stock-in payment rows = amount paid, per stock-in', $rows === [],
            $rows === [] ? 'payment rows Rs '.number_format($sum, 2).' = amount paid Rs '.number_format($paid, 2) : count($rows).' stock-ins differ: '.json_encode($rows)];
    }

    protected function vendorDues(): array
    {
        $diffs = [];
        $total = 0.0;

        foreach (Vendor::all() as $vendor) {
            $sql = (float) DB::table('stock_ins')->where('shop_id', $this->shopId)->where('vendor_id', $vendor->id)->where('status', 'active')->where('payment_status', '!=', 'paid')
                ->selectRaw('COALESCE(SUM(GREATEST(COALESCE(total_cost, 0) - amount_paid, 0)), 0) AS owed')->value('owed');
            $app = $vendor->totalOwed();
            $total += $app;

            if (round($sql, 2) !== round($app, 2)) {
                $diffs[] = "{$vendor->name}: app {$app}, SQL {$sql}";
            }
        }

        return ['Vendor dues: app (Vendor::totalOwed) vs SQL', $diffs === [], $diffs === [] ? 'all vendors agree; total Rs '.number_format($total, 2) : implode('; ', $diffs)];
    }

    protected function customerBalances(): array
    {
        $udhaarDiffs = $salesDiffs = $statementDiffs = [];
        $totals = ['udhaar' => 0.0, 'sales' => 0.0, 'statement' => 0.0];

        foreach (Customer::all() as $customer) {
            $udhaarSql = (float) DB::table('udhaar_transactions')->where('shop_id', $this->shopId)->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')
                ->selectRaw("COALESCE(SUM(CASE WHEN type IN ('given', 'repayment_reversal') THEN amount ELSE -amount END), 0) AS net")->value('net');
            $salesSql = (float) collect(DB::select(
                "SELECT GREATEST(s.total - COALESCE((SELECT SUM(p.amount) FROM sale_payments p WHERE p.sale_id = s.id), 0), 0) AS due
                 FROM sales s WHERE s.shop_id = ? AND s.customer_id = ? AND s.status <> 'cancelled'", [$this->shopId, $customer->id]))->sum('due');
            $repairsSql = (float) DB::table('repairs')->where('shop_id', $this->shopId)->where('customer_id', $customer->id)->where('status', '!=', 'cancelled')->where('payment_status', '!=', 'paid')
                ->selectRaw('COALESCE(SUM(GREATEST(total - amount_paid, 0)), 0) AS owed')->value('owed');

            $udhaarApp = $customer->udhaarBalance();
            $salesApp = $customer->salesOutstandingBalance();
            $statementApp = CustomerDuesStatement::for($customer)->total();
            $totals['udhaar'] += $udhaarApp;
            $totals['sales'] += $salesApp;
            $totals['statement'] += $statementApp;

            if (round($udhaarApp, 2) !== round($udhaarSql, 2)) {
                $udhaarDiffs[] = "{$customer->name}: app {$udhaarApp}, SQL {$udhaarSql}";
            }

            if (round($salesApp, 2) !== round($salesSql, 2)) {
                $salesDiffs[] = "{$customer->name}: app {$salesApp}, SQL {$salesSql}";
            }

            if (round($statementApp, 2) !== round($udhaarSql + $salesSql + $repairsSql, 2)) {
                $statementDiffs[] = "{$customer->name}: app {$statementApp}, SQL ".($udhaarSql + $salesSql + $repairsSql);
            }
        }

        $line = fn (string $label, array $diffs, float $total) => [$label, $diffs === [], $diffs === [] ? 'all customers agree; total Rs '.number_format($total, 2) : implode('; ', $diffs)];

        return [
            $line('Udhaar balance: app (Customer::udhaarBalance) vs SQL', $udhaarDiffs, $totals['udhaar']),
            $line('Sales outstanding: app (Customer::salesOutstandingBalance) vs SQL', $salesDiffs, $totals['sales']),
            $line('Dues statement: app (CustomerDuesStatement total) vs SQL udhaar + sales + repairs', $statementDiffs, $totals['statement']),
        ];
    }

    protected function saleArithmetic(): array
    {
        $bad = DB::select(
            'SELECT s.id, s.subtotal, s.discount_amount, s.total, COALESCE(SUM(i.line_total), 0) AS line_sum
             FROM sales s LEFT JOIN sale_items i ON i.sale_id = s.id WHERE s.shop_id = ?
             GROUP BY s.id, s.subtotal, s.discount_amount, s.total
             HAVING ROUND(s.subtotal, 2) <> ROUND(line_sum, 2) OR ROUND(s.subtotal - s.discount_amount, 2) <> ROUND(s.total, 2)', [$this->shopId]);

        return ['Each sale: lines = subtotal, subtotal - discount = total', $bad === [], $bad === [] ? 'all sales add up' : json_encode($bad)];
    }

    /** @return array<string, int> */
    public function bookCounts(): array
    {
        $counts = [];

        foreach (['money_entries', 'ledger_entries', 'ledger_outbox', 'party_ledger_entries'] as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->where('shop_id', $this->shopId)->count();
            }
        }

        return $counts;
    }

    protected function booksUntouched(?array $before): array
    {
        $counts = $this->bookCounts();

        return ['Nothing written to money entries, the ledger or the party ledger', $before === null || $before === $counts,
            'rows now '.json_encode($counts).($before === null ? '' : ', before '.json_encode($before))];
    }
}
