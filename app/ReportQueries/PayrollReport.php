<?php

namespace App\ReportQueries;

use App\Exports\TableExport;
use App\Models\Payslip;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class PayrollReport implements ScreenReport
{
    public function __construct(
        protected string $fromMonth = '',
        protected string $toMonth = '',
        protected string $employeeId = '',
        protected string $status = '',
    ) {}

    public function query(): Builder
    {
        return Payslip::withoutGlobalScope(ExcludesCancelledScope::class)
            ->inBranchOrShared()
            ->when($this->fromMonth, fn ($q) => $q->whereDate('period', '>=', $this->fromMonth.'-01'))
            ->when($this->toMonth, fn ($q) => $q->whereDate('period', '<=', $this->toMonth.'-01'))
            ->when($this->employeeId, fn ($q) => $q->where('employee_id', $this->employeeId))
            ->when($this->status, fn ($q) => $q->where('status', $this->status));
    }

    public function filtersSummary(): string
    {
        return $this->fromMonth || $this->toMonth
            ? 'Months: '.($this->fromMonth ?: 'earliest').' – '.($this->toMonth ?: 'latest')
            : 'All records';
    }

    public function summaryCards(): array
    {
        $paid = $this->query()->where('status', 'paid');

        return [
            ['label' => 'Salary Cost', 'value' => 'Rs '.number_format((float) (clone $paid)->sum('salary_cost'), 2), 'sub' => 'Paid payslips, as recorded in Expenses'],
            ['label' => 'Net Pay', 'value' => 'Rs '.number_format((float) (clone $paid)->sum('net_pay'), 2), 'sub' => 'Cash paid out'],
            ['label' => 'Advances Recovered', 'value' => 'Rs '.number_format((float) (clone $paid)->sum('advance_recovery'), 2), 'sub' => number_format((clone $paid)->count()).' paid'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Payslip', 'Month', 'Employee', 'Type', 'Rate', 'Days', 'Gross', 'Deductions', 'Advance Recovered', 'Net Pay', 'Paid On', 'Status'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest('period')->latest('id')->get()->map(function (Payslip $payslip) use ($forExcel) {
            $name = TableExportService::sanitizeCell($payslip->employee_name);
            $deductions = round((float) $payslip->cost_deductions + (float) $payslip->held_deductions, 2);
            $days = $payslip->days !== null ? (float) $payslip->days : null;
            $money = fn (float $amount) => 'Rs '.number_format($amount, 2);

            return $forExcel
                ? [$payslip->number(), $payslip->period->format('M Y'), $name, $payslip->salary_type->label(), (float) $payslip->rate, $days, $payslip->grossEarnings(), $deductions, (float) $payslip->advance_recovery, (float) $payslip->net_pay, TableExport::excelDate($payslip->paid_on), $payslip->status->label()]
                : [$payslip->number(), $payslip->period->format('M Y'), $name, $payslip->salary_type->label(), $money((float) $payslip->rate), $days ?? '—', $money($payslip->grossEarnings()), $money($deductions), $money((float) $payslip->advance_recovery), $money((float) $payslip->net_pay), $payslip->paid_on?->format('d M Y') ?? '—', $payslip->status->label()];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'string', 'string', 'currency', 'quantity', 'currency', 'currency', 'currency', 'currency', 'date', 'string'];
    }
}
