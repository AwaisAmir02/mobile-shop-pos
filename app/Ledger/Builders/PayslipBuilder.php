<?php

namespace App\Ledger\Builders;

use App\Enums\PayslipStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Model;

/**
 * A paid payslip, instead of its linked Expense (which ExpenseBuilder skips), so
 * salaries count once. Cost is what the shop bears; it splits into the cash paid,
 * the advance recovered and the amount held for someone else. On the same branch
 * as that Expense, so branch figures agree.
 */
class PayslipBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status !== PayslipStatus::Paid) {
            return [];
        }

        $date = $document->paid_on;
        $branch = $document->branch_id ?? BranchContext::current()->mainBranchIdFor($document->shop_id);
        $employee = $document->employee_id;
        $paidFrom = $document->shop_account_id !== null ? Chart::bankCode($document->shop_account_id) : Chart::CASH;

        return [
            Line::debit(Chart::SALARIES, $document->salary_cost, $date, $branch),
            Line::credit($paidFrom, $document->net_pay, $date, $branch),
            Line::credit(Chart::EMPLOYEE_ADVANCES, $document->advance_recovery, $date, $branch, 'employee', $employee),
            Line::credit(Chart::WITHHOLDINGS, $document->held_deductions, $date, $branch, 'employee', $employee),
        ];
    }

    public function description(Model $document): string
    {
        $label = 'Salary: '.$document->employee_name.', '.$document->period->format('M Y');

        return $document->status === PayslipStatus::Cancelled ? "{$label} cancelled: cash assumed returned" : $label;
    }
}
