<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only. Sizes the ledger's cutover problems for a database: only ever runs
 * SELECTs, and skips a check whose table or column isn't there yet, so it's safe
 * on a database that hasn't had the latest migrations. Run before a pilot shop.
 */
class LedgerCounts extends Command
{
    protected $signature = 'ledger:counts {--shop= : Limit to one shop id}';

    protected $description = 'Read-only counts of data the ledger cutover has to handle';

    public function handle(): int
    {
        $rows = [];

        foreach ($this->checks() as $label => [$table, $columns, $query]) {
            if (! Schema::hasTable($table) || collect($columns)->contains(fn (string $column) => ! Schema::hasColumn($table, $column))) {
                $rows[] = [$label, 'not on this database yet'];

                continue;
            }

            $builder = DB::table($table);

            if ($this->option('shop') && Schema::hasColumn($table, 'shop_id')) {
                $builder->where($table.'.shop_id', (int) $this->option('shop'));
            }

            $rows[] = [$label, number_format($query($builder)->count())];
        }

        $this->table(['Check', 'Count'], $rows);

        return self::SUCCESS;
    }

    /** @return array<string, array{0: string, 1: array<int, string>, 2: callable}> */
    protected function checks(): array
    {
        $active = fn ($query) => $query->where('status', '!=', 'cancelled');

        return [
            'Shops' => ['shops', ['id'], fn ($q) => $q],
            'Sale lines' => ['sale_items', ['id'], fn ($q) => $q],
            'Sale lines with no cost' => ['sale_items', ['cost_price'], fn ($q) => $q->whereNull('cost_price')],
            'Sale payments' => ['sale_payments', ['id'], fn ($q) => $q],
            'Sale payments with no method' => ['sale_payments', ['payment_method'], fn ($q) => $q->whereNull('payment_method')],
            'Stock-ins with no cost' => ['stock_ins', ['total_cost', 'status'], fn ($q) => $active($q)->whereNull('total_cost')],
            'Stock-ins overpaid' => ['stock_ins', ['total_cost', 'amount_paid', 'status'], fn ($q) => $active($q)->whereColumn('amount_paid', '>', 'total_cost')],
            'Stock-ins cancelled with money paid' => ['stock_ins', ['amount_paid', 'status'], fn ($q) => $q->where('status', 'cancelled')->where('amount_paid', '>', 0)],
            'Sales cancelled with money paid' => ['sales', ['status'], fn ($q) => $q->where('status', 'cancelled')->whereExists(fn ($payments) => $payments->selectRaw('1')->from('sale_payments')->whereColumn('sale_payments.sale_id', 'sales.id'))],
            'Products with stock beyond their batches' => ['products', ['stock_quantity'], fn ($q) => $q->where('stock_quantity', '>', 0)->whereRaw('stock_quantity > (SELECT COALESCE(SUM(s.quantity_remaining), 0) FROM stock_ins s WHERE s.product_id = products.id AND s.status != ?)', ['cancelled'])],
            'Products in stock with no cost' => ['products', ['stock_quantity', 'cost_price'], fn ($q) => $q->where('stock_quantity', '>', 0)->whereNull('cost_price')],
            'Expenses' => ['expenses', ['status'], $active],
            'Udhaar given directly' => ['udhaar_transactions', ['type'], fn ($q) => $q->where('type', 'given')],
            'Party ledger entries' => ['party_ledger_entries', ['id'], fn ($q) => $q],
            'Wallet loads' => ['wallet_loads', ['id'], fn ($q) => $q],
            'Balance loads' => ['balance_loads', ['id'], fn ($q) => $q],
            'Bill payments' => ['bill_payments', ['id'], fn ($q) => $q],
            'SIM sales' => ['sim_sales', ['id'], fn ($q) => $q],
            'Repairs' => ['repairs', ['id'], fn ($q) => $q],
            'NADRA verifications' => ['nadra_verifications', ['id'], fn ($q) => $q],
            'Service payments' => ['service_payments', ['id'], fn ($q) => $q],
        ];
    }
}
