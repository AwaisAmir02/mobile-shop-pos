<?php

namespace App\Ledger\Builders;

use App\Enums\PartyLedgerDirection;
use App\Enums\PartyLedgerEntryKind;
use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * A hand-entered balance. It says nothing about cash or profit, so the other
 * side stays in equity: Opening Balances, or Prior Period for an adjustment.
 * The screens keep these outside Vendor::totalOwed, so Ledger Health shows them
 * apart. Debit raises a customer's balance and lowers a vendor's, which is
 * exactly what a debit does to Receivable and Payable.
 */
class PartyLedgerBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $isCustomer = $document->customer_id !== null;
        $account = $isCustomer ? Chart::RECEIVABLE : Chart::PAYABLE;
        $party = $isCustomer ? ['customer', $document->customer_id] : ['vendor', $document->vendor_id];
        $other = $document->kind === PartyLedgerEntryKind::Opening ? Chart::OPENING : Chart::PRIOR_PERIOD;
        $date = $document->entry_date;
        $branch = $document->branch_id;

        return $document->direction === PartyLedgerDirection::Debit
            ? [Line::debit($account, $document->amount, $date, $branch, ...$party), Line::credit($other, $document->amount, $date, $branch)]
            : [Line::credit($account, $document->amount, $date, $branch, ...$party), Line::debit($other, $document->amount, $date, $branch)];
    }

    public function description(Model $document): string
    {
        return 'Party ledger '.$document->kind->value.($document->note ? ': '.$document->note : '');
    }
}
