<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Enums\WalletLoadDirection;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerSources;
use App\Ledger\Line;
use App\Models\CounterServicePayment;
use Illuminate\Database\Eloquent\Model;

/**
 * Wallet float lives in Agent Float & Clearing.
 *
 * Cash in is a pass-through like a balance load: the float pays the amount out,
 * the customer owes the total and pays it in cash.
 *
 * Cash out runs the other way: the till hands over the cash, the customer owes
 * the total and sends it into the shop's wallet (the float). Whatever they
 * haven't sent yet stays receivable, as with cash in: the shop always pays out
 * in full, so a shortfall is always the customer's due.
 *
 * Cash Out rows saved before that rule (excluded_from_dues) keep their old
 * posting, settled in full, so nothing already posted moves.
 */
class WalletLoadBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function income(Model $document): float
    {
        return round((float) $document->fee - (float) $document->discount, 2);
    }

    protected function passThrough(Model $document): float
    {
        return round((float) $document->total - $this->income($document), 2);
    }

    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled || $document->direction !== WalletLoadDirection::CashOut) {
            return parent::lines($document);
        }

        $date = $document->created_at;
        $branch = $document->branch_id;

        if ($document->excluded_from_dues) {
            return [
                Line::debit(Chart::CLEARING, $document->total, $date, $branch),
                Line::credit(Chart::CASH, $this->passThrough($document), $date, $branch),
                Line::credit(Chart::SERVICE_INCOME, $this->income($document), $date, $branch),
            ];
        }

        $customer = $document->customer_id;
        $lines = [
            Line::debit(Chart::RECEIVABLE, $document->total, $date, $branch, 'customer', $customer),
            Line::credit(Chart::CASH, $this->passThrough($document), $date, $branch),
            Line::credit(Chart::SERVICE_INCOME, $this->income($document), $date, $branch),
        ];

        // The customer's money always arrives in the float, on the day it was recorded.
        $unrecorded = (float) $document->amount_paid;
        $key = LedgerSources::documentFor($document)[0];

        foreach (CounterServicePayment::withoutGlobalScopes()->where('source_type', $key)->where('source_id', $document->id)->get() as $payment) {
            $lines[] = Line::debit(Chart::CLEARING, $payment->amount, $payment->paid_on, $branch);
            $lines[] = Line::credit(Chart::RECEIVABLE, $payment->amount, $payment->paid_on, $branch, 'customer', $customer);
            $unrecorded -= (float) $payment->amount;
        }

        $lines[] = Line::debit(Chart::CLEARING, round($unrecorded, 2), $date, $branch);
        $lines[] = Line::credit(Chart::RECEIVABLE, round($unrecorded, 2), $date, $branch, 'customer', $customer);

        return $lines;
    }

    protected function label(Model $document): string
    {
        return 'Wallet '.str_replace('_', ' ', $document->direction->value).': '.$document->provider;
    }
}
