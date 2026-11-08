<?php

use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\ReportQueries\ProfitReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Gross Profit')] class extends Component
{
    use GuardsExportSize, Toasts;

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->authorizeCosts();

        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    /** Profit reveals what goods cost, so it follows the same permission as the sale screen's cost tooltip. */
    protected function authorizeCosts(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-view-cost-price'), 403);
    }

    protected function report(): ProfitReport
    {
        return new ProfitReport($this->from, $this->to);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'cards' => $report->summaryCards(),
            'notice' => $report->noCostNotice(),
            'totals' => $report->totals(),
            'rows' => $report->byProduct(),
        ];
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $this->authorizeCosts();
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Gross Profit', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $this->authorizeCosts();
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Gross Profit', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('reports.index') }}" back-label="Reports">Gross Profit</x-ui.page-header>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>
            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>
        </div>

        <x-ui.export-dropdown />
    </div>

    <p class="mb-4 text-sm text-slate-600">
        What your goods sold for, minus what those same goods cost you. Expenses, write-offs, repairs and other services aren't counted here.
        This is a different figure from <span class="font-medium text-slate-800">Net (Revenue − Expenses)</span> on the Dashboard, which subtracts expenses but not the cost of goods.
    </p>

    @if ($notice)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            <p class="font-semibold">{{ $notice }}</p>
            <p class="mt-1">Rs {{ number_format($totals['uncostedRevenue'], 2) }} of sales from those lines is included in Sales Revenue but left out of Gross Profit and Gross Margin, because there is no cost to set against it.</p>
        </div>
    @endif

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" :tone="$card['label'] === 'Gross Profit' ? 'brand' : 'default'" />
        @endforeach
    </div>

    @if ($rows === [])
        <x-ui.empty-state title="No sales in this period" description="Pick another date range to see gross profit." />
    @else
        <x-ui.table :headers="['Product', 'Quantity Sold', 'Sales', 'Cost of Goods', 'Gross Profit']">
            @foreach ($rows as $i => $row)
                <x-ui.table-row wire:key="profit-row-{{ $i }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        {{ $row['name'] }}
                        @if ($row['noCostLines'] > 0)
                            <x-ui.badge variant="warning">{{ $row['noCostLines'] }} with no cost</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $row['unit']->groupedAmount($row['quantity']) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($row['sales'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $row['cogs'] !== null ? 'Rs '.number_format($row['cogs'], 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">{{ $row['profit'] !== null ? 'Rs '.number_format($row['profit'], 2) : '—' }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        @if ($totals['invoiceDiscount'] > 0)
            <p class="mt-3 text-xs text-slate-500">Rows show each product before invoice discounts. The Rs {{ number_format($totals['invoiceDiscount'], 2) }} of invoice discounts in this period is taken off in the totals above.</p>
        @endif
    @endif
</div>
