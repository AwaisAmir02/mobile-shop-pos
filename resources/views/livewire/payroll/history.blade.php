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
        <x-ui.page-header>Payroll History</x-ui.page-header>
    </x-slot>

    <x-payroll.nav active="payroll.history" />

    <x-ui.filter-bar :active="(int) filled($employeeId) + (int) filled($statusFilter)">
            <x-ui.field label="From" name="fromMonth" for="fromMonth" class="w-[calc(50%-0.375rem)] sm:w-44">
                <x-ui.input wire:model.live="fromMonth" id="fromMonth" type="month" />
            </x-ui.field>
            <x-ui.field label="To" name="toMonth" for="toMonth" class="w-[calc(50%-0.375rem)] sm:w-44">
                <x-ui.input wire:model.live="toMonth" id="toMonth" type="month" />
            </x-ui.field>
            <x-ui.field label="Employee" name="employeeId" for="historyEmployee" class="w-full sm:w-52">
                <x-ui.select wire:model.live="employeeId" id="historyEmployee">
                    <option value="">Everyone</option>
                    @foreach ($employees as $person)
                        <option value="{{ $person->id }}">{{ $person->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Status" name="statusFilter" for="historyStatus" class="w-full sm:w-40">
                <x-ui.select wire:model.live="statusFilter" id="historyStatus">
                    <option value="">All</option>
                    @foreach (PayslipStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-3 sm:gap-4">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </div>

    @if ($payslips->isEmpty())
        <x-ui.empty-state title="No payslips" description="Payslips from Run Payroll show up here." />
    @else
        <x-ui.table stack :headers="['Payslip', 'Month', 'Employee', 'Earnings', 'Net Pay', 'Paid On', 'Status', '']">
            @foreach ($payslips as $payslip)
                <x-ui.table-row wire:key="history-{{ $payslip->id }}" @class(['opacity-50' => $payslip->isCancelled()])>
                    <x-ui.table-cell primary class="font-medium text-ink">{{ $payslip->number() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Month">{{ $payslip->period->format('M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Employee">{{ $payslip->employee_name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Earnings">Rs {{ number_format($payslip->grossEarnings(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Net Pay" class="font-semibold text-ink">Rs {{ number_format($payslip->net_pay, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Paid On">{{ $payslip->paid_on?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status"><x-ui.badge :variant="$payslip->status->badge()">{{ $payslip->status->label() }}</x-ui.badge></x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.button size="sm" variant="ghost" wire:click="downloadPdf({{ $payslip->id }})">PDF</x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $payslips->links() }}</div>
    @endif
</div>
