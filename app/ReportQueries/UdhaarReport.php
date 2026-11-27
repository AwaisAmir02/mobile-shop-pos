<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\ServicePayment;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Models\WalletLoad;
use App\Services\TableExportService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Udhaar's own "table" isn't a single filtered Eloquent query the way the
 * other nine screens' are — it's a computed roll-up across seven other
 * models (see otherDueTotalsByCustomer()) combined with the real Udhaar
 * loan ledger (see UdhaarCombinedBalanceTest for the rules this encodes).
 * query() still returns a real, correctly-scoped Builder for interface
 * consistency and internal reuse, but both this screen's own History-
 * equivalent list and the Reports screen render its rows via rows()/
 * tableRows() rather than paginating query() directly — Udhaar's list
 * was never paginated even before this refactor.
 */
class UdhaarReport implements ScreenReport
{
    public function query(): Builder
    {
        return Customer::query()->whereIn('id', $this->relevantCustomerIds());
    }

    protected function relevantCustomerIds(): Collection
    {
        return UdhaarTransaction::balancesByCustomer()->keys()
            ->merge($this->otherDueTotalsByCustomer()->keys())
            ->unique();
    }

    /**
     * Every customer's total outstanding amount across the non-Udhaar
     * sources also surfaced on their own consolidated "Other Amounts Owed"
     * view, keyed by customer_id. Wallet Loads count in both directions
     * (the shop always pays out in full, so any shortfall is the customer's),
     * except older Cash Out rows marked excluded_from_dues. Shop-wide, like the Udhaar balances it is
     * added to, whichever branch the viewer works in.
     */
    protected function otherDueTotalsByCustomer(): Collection
    {
        return BranchContext::current()->shopWide(fn () => $this->collectOtherDueTotals());
    }

    protected function collectOtherDueTotals(): Collection
    {
        $totals = collect();

        // Added up in the order dueRecords() lists them, one record at a time.
        foreach ($this->collectDueRecords(null) as $record) {
            $totals[$record['customer_id']] = ($totals[$record['customer_id']] ?? 0.0) + $record['amount'];
        }

        return $totals;
    }

    /**
     * Every unpaid amount behind the figures above, one row per record: the
     * counter services, then sales. Dues aging reads these same rows, so its
     * bills always add up to what this list shows. Shop-wide.
     *
     * @return list<array{customer_id: int, source: string, id: int, amount: float, date: mixed, due_date: mixed}>
     */
    public function dueRecords(?int $customerId = null): array
    {
        return BranchContext::current()->shopWide(fn () => $this->collectDueRecords($customerId));
    }

    protected function collectDueRecords(?int $customerId): array
    {
        $unpaidOrPartial = fn ($query) => $query
            ->whereNotNull('customer_id')
            ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
            ->where('payment_status', '!=', PaymentStatus::Paid->value);

        $records = [];

        $collect = function (string $source, Collection $models, \Closure $amountOf) use (&$records) {
            $models->each(function ($model) use ($source, $amountOf, &$records) {
                $due = $amountOf($model);

                if ($due > 0) {
                    $records[] = [
                        'customer_id' => (int) $model->customer_id,
                        'source' => $source,
                        'id' => $model->id,
                        'amount' => $due,
                        'date' => $model->created_at,
                        'due_date' => $source === 'sale' ? $model->due_date : null,
                    ];
                }
            });
        };

        $collect('wallet_load', $unpaidOrPartial(WalletLoad::query()->countsAsDue())->get(), fn (WalletLoad $m) => $m->amountOwed());
        $collect('balance_load', $unpaidOrPartial(BalanceLoad::query())->get(), fn (BalanceLoad $m) => $m->amountOwed());
        $collect('bill_payment', $unpaidOrPartial(BillPayment::query())->get(), fn (BillPayment $m) => $m->amountOwed());
        $collect('repair', $unpaidOrPartial(Repair::query())->get(), fn (Repair $m) => $m->amountOwed());
        $collect('nadra_verification', $unpaidOrPartial(NadraVerification::query())->get(), fn (NadraVerification $m) => $m->amountOwed());
        $collect('sim_sale', $unpaidOrPartial(SimSale::query())->get(), fn (SimSale $m) => $m->amountOwed());
        $collect('service_payment', $unpaidOrPartial(ServicePayment::query())->get(), fn (ServicePayment $m) => $m->amountOwed());
        // Only sales the customer can still owe leave the database (Sale::scopeOwedByCustomer:
        // owing(), less cash on delivery a courier holds); amountDue() still decides, as before.
        $collect(
            'sale',
            Sale::query()->whereNotNull('customer_id')
                ->when($customerId !== null, fn ($q) => $q->where('customer_id', $customerId))
                ->owedByCustomer()->orderBy('sales.id')->get(),
            fn (Sale $m) => $m->amountDue()
        );

        return $records;
    }

    public function rows(): Collection
    {
        $balances = UdhaarTransaction::balancesByCustomer();
        $otherDueTotals = $this->otherDueTotalsByCustomer();
        $customerIds = $balances->keys()->merge($otherDueTotals->keys())->unique();

        return Customer::query()
            ->whereIn('id', $customerIds)
            ->orderBy('name')
            ->get()
            ->map(function (Customer $customer) use ($balances, $otherDueTotals) {
                $otherDue = $otherDueTotals[$customer->id] ?? 0.0;
                $balance = ($balances[$customer->id] ?? 0.0) + $otherDue;

                return [
                    'customer' => $customer,
                    'balance' => $balance,
                    'status' => match (true) {
                        $balance > 0 => 'due',
                        $balance < 0 => 'advance',
                        default => 'settled',
                    },
                    'hasOtherDues' => $otherDue > 0,
                ];
            })
            ->sortByDesc('balance')
            ->values();
    }

    /**
     * (Which balance to use where: docs/customer-balances.md.)
     *
     * Combines every customer's real Udhaar loan balance with their
     * other-source dues (rows() above already added the two together per
     * customer) — this is the shop's one true total of everything
     * currently owed via Udhaar-reachable balances.
     */
    public function totalDue(): float
    {
        return $this->rows()->sum(fn ($row) => max($row['balance'], 0));
    }

    public function filtersSummary(): string
    {
        return 'All records';
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Outstanding', 'value' => 'Rs '.number_format($this->totalDue(), 2), 'sub' => 'Across all customers who currently owe money'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Customer', 'Phone', 'Balance', 'Status', 'Other Dues'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->rows()->map(function (array $row) use ($forExcel) {
            $balance = abs($row['balance']);
            $status = ucfirst($row['status']);
            $phone = TableExportService::sanitizeCell($row['customer']->phone ?? '');

            return $forExcel
                ? [$row['customer']->name, $phone, (float) $balance, $status, $row['hasOtherDues'] ? 'Yes' : 'No']
                : [$row['customer']->name, $phone ?: '—', 'Rs '.number_format($balance, 2), $status, $row['hasOtherDues'] ? 'Yes' : 'No'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'currency', 'string', 'string'];
    }
}
