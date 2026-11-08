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
        <x-ui.page-header>
            Vendor Invoices
            <x-slot:actions>
                @if ($canCreate)
                    <x-ui.button href="{{ route('purchase-orders.invoices.create') }}" wire:navigate size="sm">Record Invoice</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="purchasing" label="Purchasing sections" />

    @unless ($enabled)
        <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
            Purchase orders aren't on your plan right now, so new invoices can't be recorded or approved. Your invoices are still here, and what you owe vendors isn't affected.
        </div>
    @endunless

    <x-ui.filter-bar :active="(int) filled($statusFilter) + (int) filled($vendorFilter)">
        <x-ui.field label="Status" name="statusFilter" for="invoiceStatusFilter" class="w-full sm:w-48">
            <x-ui.select wire:model.live="statusFilter" id="invoiceStatusFilter">
                <option value="">All</option>
                @foreach (VendorInvoiceStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Vendor" name="vendorFilter" for="invoiceVendorFilter" class="w-full sm:w-56">
            <x-ui.select wire:model.live="vendorFilter" id="invoiceVendorFilter">
                <option value="">Everyone</option>
                @foreach ($vendors as $vendor)
                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.stat-row class="lg:grid-cols-2">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </x-ui.stat-row>

    @if ($invoices->isEmpty())
        <x-ui.empty-state title="No vendor invoices" description="Record a vendor's bill to check it against what you ordered and received." />
    @else
        <x-ui.table stack :headers="['Invoice', 'Vendor\'s No.', 'Vendor', 'Date', 'Billed', 'Owed', 'Status']">
            @foreach ($invoices as $invoice)
                <x-ui.table-row wire:key="invoice-{{ $invoice->id }}" @class(['opacity-50' => $invoice->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold"><a href="{{ route('purchase-orders.invoices.show', $invoice->id) }}" wire:navigate class="text-link hover:underline">{{ $invoice->number() }}</a></x-ui.table-cell>
                    <x-ui.table-cell label="Vendor's No.">{{ $invoice->invoice_number }}</x-ui.table-cell>
                    <x-ui.table-cell label="Vendor">{{ $invoice->vendor?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $invoice->invoice_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Billed">Rs {{ number_format($invoice->billedTotal(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Owed" class="font-semibold text-ink">{{ $invoice->isApproved() ? 'Rs '.number_format($invoice->amountOwed(), 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status"><x-ui.badge :variant="$invoice->status->badge()">{{ $invoice->status->label() }}</x-ui.badge></x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $invoices->links() }}</div>
    @endif
</div>
