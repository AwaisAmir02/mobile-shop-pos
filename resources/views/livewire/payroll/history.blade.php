<?php

use App\Enums\PayslipStatus;
use App\Livewire\Concerns\GuardsExportSize;
use App\Models\Employee;
use App\Models\Payslip;
use App\Models\Scopes\ExcludesCancelledScope;
use App\ReportQueries\PayrollReport;
use App\Services\PayslipPdfService;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Payroll History')] class extends Component
{
    use GuardsExportSize, WithPagination;

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('payroll'), 403);
    }

    public string $fromMonth = '';
    public string $toMonth = '';
    public string $employeeId = '';
    public string $statusFilter = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['fromMonth', 'toMonth', 'employeeId', 'statusFilter'], true)) {
            $this->resetPage();
        }
    }

    protected function report(): PayrollReport
    {
        return new PayrollReport($this->fromMonth, $this->toMonth, $this->employeeId, $this->statusFilter);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'payslips' => $report->query()->latest('period')->latest('id')->paginate(15),
            'cards' => $report->summaryCards(),
            'employees' => Employee::query()->inBranchOrShared()->orderBy('name')->get(),
        ];
    }

    public function downloadPdf(int $id, PayslipPdfService $pdf): StreamedResponse
    {
        return $pdf->download(Payslip::withoutGlobalScope(ExcludesCancelledScope::class)->inBranchOrShared()->findOrFail($id));
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Payroll History', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Payroll History', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Payroll History</h1>
    </x-slot>

    <x-payroll.nav active="payroll.history" />

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-end">
            <x-ui.field label="From" name="fromMonth" for="fromMonth" class="sm:max-w-[11rem]">
                <x-ui.input wire:model.live="fromMonth" id="fromMonth" type="month" />
            </x-ui.field>
            <x-ui.field label="To" name="toMonth" for="toMonth" class="sm:max-w-[11rem]">
                <x-ui.input wire:model.live="toMonth" id="toMonth" type="month" />
            </x-ui.field>
            <x-ui.field label="Employee" name="employeeId" for="historyEmployee" class="sm:max-w-[14rem]">
                <x-ui.select wire:model.live="employeeId" id="historyEmployee">
                    <option value="">Everyone</option>
                    @foreach ($employees as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Status" name="statusFilter" for="historyStatus" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="statusFilter" id="historyStatus">
                    <option value="">All</option>
                    @foreach (PayslipStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <x-ui.export-dropdown />
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </div>

    @if ($payslips->isEmpty())
        <x-ui.empty-state title="No payslips" description="Payslips from Run Payroll show up here." />
    @else
        <x-ui.table :headers="['Payslip', 'Month', 'Employee', 'Earnings', 'Net Pay', 'Paid On', 'Status', '']">
            @foreach ($payslips as $payslip)
                <x-ui.table-row wire:key="history-{{ $payslip->id }}" @class(['opacity-50' => $payslip->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $payslip->number() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payslip->period->format('M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payslip->employee_name }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($payslip->grossEarnings(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($payslip->net_pay, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payslip->paid_on?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell><x-ui.badge :variant="$payslip->status->badge()">{{ $payslip->status->label() }}</x-ui.badge></x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadPdf({{ $payslip->id }})">PDF</x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $payslips->links() }}</div>
    @endif
</div>
