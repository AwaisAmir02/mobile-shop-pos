<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\UdhaarTransaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * What a customer sees on their own ledger page.
 *
 * The total is CustomerDuesStatement's, the same figure their reminder shows.
 * The history is udhaar plus sales bought on credit and their payments, with
 * a running balance that ends at the udhaar balance plus what those sales
 * still owe. Counter services still owed (repairs, bills…) are listed apart,
 * as on the shop's Party Ledger. Sales paid in full when made add nothing to
 * the balance and are left out.
 */
class CustomerLedgerView
{
    public const HISTORY_ROWS = 60;

    public function __construct(public readonly Customer $customer)
    {
    }

    public function total(): float
    {
        return round(CustomerDuesStatement::for($this->customer)->total(), 2);
    }

    /** Unpaid items, oldest first, plus the udhaar loan balance. */
    public function owed(): array
    {
        $statement = CustomerDuesStatement::for($this->customer);

        return [
            'udhaar' => round($statement->udhaarBalance(), 2),
            'items' => $statement->items()->sortBy('date')->values(),
        ];
    }

    /** @return Collection<int, array{date: CarbonInterface, description: string, detail: ?string, debit: float, credit: float, balance: float}> newest first */
    public function history(): Collection
    {
        $rows = collect();

        BranchContext::current()->shopWide(function () use ($rows) {
            Sale::where('customer_id', $this->customer->id)->with('payments')->get()->each(function (Sale $sale) use ($rows) {
                $paidAtSale = (float) $sale->payments->filter(fn ($p) => $p->payment_date->isSameDay($sale->created_at))->sum('amount');

                if ($paidAtSale >= (float) $sale->total - 0.009) {
                    return;
                }

                $rows->push($this->row($sale->created_at, 'Bought on credit', $sale->invoiceNumber(), (float) $sale->total, 0, 'a'.$sale->id));

                foreach ($sale->payments as $payment) {
                    if ((float) $payment->amount > 0) {
                        $rows->push($this->row($payment->payment_date, 'Payment', $sale->invoiceNumber(), 0, (float) $payment->amount, 'b'.$payment->id));
                    }
                }
            });
        });

        $this->customer->udhaarTransactions->each(function (UdhaarTransaction $t) use ($rows) {
            $rows->push($this->row($t->transaction_date, $t->type->sign() > 0 ? 'Udhaar given' : 'Udhaar paid back', $t->note, $t->type->sign() > 0 ? (float) $t->amount : 0, $t->type->sign() > 0 ? 0 : (float) $t->amount, 'c'.$t->id));
        });

        $balance = 0.0;

        return $rows->sortBy('sort')->values()
            ->map(function (array $row) use (&$balance) {
                $balance = round($balance + $row['debit'] - $row['credit'], 2);

                return $row + ['balance' => $balance];
            })
            ->reverse()
            ->take(self::HISTORY_ROWS)
            ->values();
    }

    /** Same day: what was owed comes before what was paid (payments carry a date, not a time). */
    protected function row(CarbonInterface $date, string $description, ?string $detail, float $debit, float $credit, string $tiebreak): array
    {
        return ['date' => $date, 'description' => $description, 'detail' => $detail, 'debit' => $debit, 'credit' => $credit, 'sort' => $date->format('Ymd').($debit > 0 ? '0' : '1').$tiebreak];
    }
}
