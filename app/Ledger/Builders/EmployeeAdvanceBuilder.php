<?php

namespace App\Ledger\Builders;

use App\Enums\AdvanceType;
use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/** Money an employee owes back: an asset, not a cost. A deduction from salary posts from its payslip, not here. */
class EmployeeAdvanceBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled || $document->type === AdvanceType::Deducted) {
            return [];
        }

        $date = $document->transaction_date;
        $employee = $document->employee_id;
        $account = $document->shop_account_id !== null ? Chart::bankCode($document->shop_account_id) : Chart::CASH;

        return $document->type === AdvanceType::Given
            ? [Line::debit(Chart::EMPLOYEE_ADVANCES, $document->amount, $date, null, 'employee', $employee), Line::credit($account, $document->amount, $date)]
            : [Line::debit($account, $document->amount, $date), Line::credit(Chart::EMPLOYEE_ADVANCES, $document->amount, $date, null, 'employee', $employee)];
    }

    public function description(Model $document): string
    {
        return 'Advance: '.$document->type->label();
    }
}
