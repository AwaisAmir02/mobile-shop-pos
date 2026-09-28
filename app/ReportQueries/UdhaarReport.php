<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Models\WalletLoad;
use App\Services\TableExportService;
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
     * view, keyed by customer_id. Wallet Load Cash Out is intentionally
     * excluded — its "owed" amount means the shop owes the customer, not
     * the other way around.
     */
    protected function otherDueTotalsByCustomer(): Collection
    {
        $unpaidOrPartial = fn ($query) => $query
            ->whereNotNull('customer_id')
            ->where('payment_status', '!=', PaymentStatus::Paid->value);

        $totals = collect();

        $accumulate = function (Collection $records, \Closure $amountOf) use (&$totals) {
            $records->each(function ($record) use ($amountOf, &$totals) {
                $due = $amountOf($record);

                if ($due > 0) {
                    $totals[$record->customer_id] = ($totals[$record->customer_id] ?? 0.0) + $due;
                }
            });
        };

        $accumulate($unpaidOrPartial(WalletLoad::query()->where('direction', 'cash_in'))->get(), fn (WalletLoad $m) => $m->amountOwed());
        $accumulate($unpaidOrPartial(BalanceLoad::query())->get(), fn (BalanceLoad $m) => $m->amountOwed());
        $accumulate($unpaidOrPartial(BillPayment::query())->get(), fn (BillPayment $m) => $m->amountOwed());
        $accumulate($unpaidOrPartial(Repair::query())->get(), fn (Repair $m) => $m->amountOwed());
        $accumulate($unpaidOrPartial(NadraVerification::query())->get(), fn (NadraVerification $m) => $m->amountOwed());
        $accumulate($unpaidOrPartial(SimSale::query())->get(), fn (SimSale $m) => $m->amountOwed());
        $accumulate(
            Sale::query()->whereNotNull('customer_id')->withSum('payments', 'amount')->get(),
            fn (Sale $m) => $m->amountDue()
        );

        return $totals;
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
