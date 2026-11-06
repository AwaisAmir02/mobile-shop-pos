<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\LedgerSources;
use App\Ledger\Line;
use App\Models\CounterServicePayment;
use Illuminate\Database\Eloquent\Model;

/**
 * The counter services share one shape: the customer owes a total and has paid
 * some of it, recorded as a running amount with no payment dates or method. So a
 * payment posts on the record's own date, to Not Classified. A pass-through (a
 * load, a bill) is the shop's money moving on someone's behalf, so only what the
 * shop keeps is income; the rest goes through Clearing.
 */
abstract class CounterServiceBuilder implements EntryBuilder
{
    abstract protected function incomeAccount(): string;

    abstract protected function label(Model $document): string;

    protected function total(Model $document): float
    {
        return (float) $document->total;
    }

    /** The part that isn't the shop's to keep. */
    protected function passThrough(Model $document): float
    {
        return 0.0;
    }

    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $date = $document->created_at;
        $branch = $document->branch_id;
        $customer = $document->customer_id;
        $total = $this->total($document);
        $passThrough = $this->passThrough($document);

        $lines = [
            Line::debit(Chart::RECEIVABLE, $total, $date, $branch, 'customer', $customer),
            Line::credit(Chart::CLEARING, $passThrough, $date, $branch),
            Line::credit($this->incomeAccount(), round($total - $passThrough, 2), $date, $branch),
        ];

        // Money with a recorded source posts through it, on its date. Whatever's left has no record.
        $unrecorded = (float) $document->amount_paid;
        $key = LedgerSources::documentFor($document)[0];

        foreach (CounterServicePayment::withoutGlobalScopes()->where('source_type', $key)->where('source_id', $document->id)->get() as $payment) {
            $lines[] = Line::debit(Chart::paidThrough($payment->payment_method, $payment->shop_account_id), $payment->amount, $payment->paid_on, $branch);
            $lines[] = Line::credit(Chart::RECEIVABLE, $payment->amount, $payment->paid_on, $branch, 'customer', $customer);
            $unrecorded -= (float) $payment->amount;
        }

        $lines[] = Line::debit(Chart::NOT_CLASSIFIED, round($unrecorded, 2), $date, $branch);
        $lines[] = Line::credit(Chart::RECEIVABLE, round($unrecorded, 2), $date, $branch, 'customer', $customer);

        return $lines;
    }

    public function description(Model $document): string
    {
        $label = $this->label($document);

        if ($document->status !== RecordStatus::Cancelled) {
            return $label;
        }

        return (float) $document->amount_paid > 0 ? "{$label} cancelled: cash assumed returned" : "{$label} cancelled";
    }
}
