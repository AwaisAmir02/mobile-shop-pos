<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Enums\UdhaarTransactionType;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * Udhaar given directly is cash lent, so it leaves Cash. These rows only ever
 * come from the Udhaar screens: goods on credit are a sale's own shortfall, and
 * SaleBuilder posts those against revenue. A repayment doesn't say how it was
 * paid, so it goes to Not Classified.
 */
class UdhaarBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $date = $document->transaction_date;
        $branch = $document->branch_id;
        $customer = $document->customer_id;
        $amount = $document->amount;

        return match ($document->type) {
            UdhaarTransactionType::Given => [
                Line::debit(Chart::RECEIVABLE, $amount, $date, $branch, 'customer', $customer),
                Line::credit(Chart::CASH, $amount, $date, $branch),
            ],
            UdhaarTransactionType::GivenReversal => [
                Line::credit(Chart::RECEIVABLE, $amount, $date, $branch, 'customer', $customer),
                Line::debit(Chart::CASH, $amount, $date, $branch),
            ],
            UdhaarTransactionType::Repayment => [
                Line::debit(Chart::NOT_CLASSIFIED, $amount, $date, $branch),
                Line::credit(Chart::RECEIVABLE, $amount, $date, $branch, 'customer', $customer),
            ],
            UdhaarTransactionType::RepaymentReversal => [
                Line::credit(Chart::NOT_CLASSIFIED, $amount, $date, $branch),
                Line::debit(Chart::RECEIVABLE, $amount, $date, $branch, 'customer', $customer),
            ],
        };
    }

    public function description(Model $document): string
    {
        return 'Udhaar: '.$document->type->value;
    }
}
