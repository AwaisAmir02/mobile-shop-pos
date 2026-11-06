<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

class ExpenseBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        // A paid salary posts from its payslip, which knows the cash and deductions. Posting both would count it twice.
        if ($document->status === RecordStatus::Cancelled || $document->payslip_id !== null) {
            return [];
        }

        return [
            Line::debit(Chart::EXPENSES, $document->amount, $document->expense_date, $document->branch_id),
            Line::credit(Chart::NOT_CLASSIFIED, $document->amount, $document->expense_date, $document->branch_id),
        ];
    }

    public function description(Model $document): string
    {
        return 'Expense: '.$document->description;
    }
}
