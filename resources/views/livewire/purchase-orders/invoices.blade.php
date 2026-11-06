<?php

use App\Enums\VendorInvoiceStatus;
use App\Livewire\Concerns\GuardsExportSize;
use App\Models\Vendor;
use App\ReportQueries\VendorInvoiceReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Vendor Invoices')] class extends Component
{
    use GuardsExportSize, WithPagination;

    public string $statusFilter = '';
    public string $vendorFilter = '';

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('vendor-invoices') || Auth::user()->hasAccessTo('vendor-invoices-approve'), 403);
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['statusFilter', 'vendorFilter'], true)) {
            $this->resetPage();
        }
    }

    protected function report(): VendorInvoiceReport
    {
        return new VendorInvoiceReport($this->statusFilter, $this->vendorFilter);
    }

    public function with(): array
    {
        $report = $this->report();
        $shop = Auth::user()->shop;

        return [
            'invoices' => $report->query()->latest('invoice_date')->latest('id')->paginate(15),
            'cards' => $report->summaryCards(),
            'vendors' => Vendor::query()->orderBy('name')->get(),
            'enabled' => (bool) $shop?->purchase_orders_enabled,
            'canCreate' => $shop?->purchase_orders_enabled && Auth::user()->hasAccessTo('vendor-invoices'),
        ];
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Vendor Invoices', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Vendor Invoices', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Vendor Invoices</h1>
            <div class="flex items-center gap-4">
                <a href="{{ route('purchase-orders.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← Purchase Orders</a>
                @if ($canCreate)
                    <a href="{{ route('purchase-orders.invoices.create') }}" wire:navigate><x-ui.button size="sm">Record Invoice</x-ui.button></a>
                @endif
            </div>
        </div>
    </x-slot>

    @unless ($enabled)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Purchase orders aren't on your plan right now, so new invoices can't be recorded or approved. Your invoices are still here, and what you owe vendors isn't affected.
        </div>
    @endunless

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <x-ui.field label="Status" name="statusFilter" for="invoiceStatusFilter" class="sm:max-w-[12rem]">
                <x-ui.select wire:model.live="statusFilter" id="invoiceStatusFilter">
                    <option value="">All</option>
                    @foreach (VendorInvoiceStatus::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Vendor" name="vendorFilter" for="invoiceVendorFilter" class="sm:max-w-[14rem]">
                <x-ui.select wire:model.live="vendorFilter" id="invoiceVendorFilter">
                    <option value="">Everyone</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>
        <x-ui.export-dropdown />
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </div>

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No vendor invoices" description="Record a vendor's bill to check it against what you ordered and received." />
    @else
        <x-ui.table :headers="['Invoice', 'Vendor\'s No.', 'Vendor', 'Date', 'Billed', 'Owed', 'Status']">
            @foreach ($invoices as $invoice)
                <x-ui.table-row wire:key="invoice-{{ $invoice->id }}" @class(['opacity-50' => $invoice->isCancelled()])>
                    <x-ui.table-cell class="font-medium"><a href="{{ route('purchase-orders.invoices.show', $invoice->id) }}" wire:navigate class="text-brand-700 hover:text-brand-800">{{ $invoice->number() }}</a></x-ui.table-cell>
                    <x-ui.table-cell>{{ $invoice->invoice_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $invoice->vendor?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $invoice->invoice_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($invoice->billedTotal(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">{{ $invoice->isApproved() ? 'Rs '.number_format($invoice->amountOwed(), 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell><x-ui.badge :variant="$invoice->status->badge()">{{ $invoice->status->label() }}</x-ui.badge></x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
