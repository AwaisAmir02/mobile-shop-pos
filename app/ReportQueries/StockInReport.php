<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Models\Vendor;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class StockInReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $productFilter = '',
        protected string $paymentStatusFilter = '',
        protected string $vendorFilter = '',
        protected bool $includeCancelled = false,
    ) {}

    public function query(): Builder
    {
        $query = StockIn::query()
            ->when($this->from, fn ($query) => $query->whereDate('stock_date', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('stock_date', '<=', $this->to))
            ->when($this->productFilter, fn ($query) => $query->where('product_id', $this->productFilter))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter))
            ->when($this->vendorFilter, fn ($query) => $query->where('vendor_id', $this->vendorFilter));

        if ($this->includeCancelled) {
            $query->withoutGlobalScope(ExcludesCancelledScope::class);
        }

        return $query;
    }

    /** Totals always exclude cancelled entries, regardless of $includeCancelled. */
    public function totalUnits(): int
    {
        return (int) $this->query()->where('stock_ins.status', '!=', 'cancelled')->sum('quantity');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) StockIn::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (StockIn $entry) => $entry->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->productFilter) {
            $parts[] = 'Product: '.(Product::find($this->productFilter)?->name ?? 'Unknown');
        }

        if ($this->paymentStatusFilter) {
            $parts[] = 'Payment Status: '.PaymentStatus::from($this->paymentStatusFilter)->label();
        }

        if ($this->vendorFilter) {
            $parts[] = 'Vendor: '.(Vendor::find($this->vendorFilter)?->name ?? 'Unknown');
        }

        return $parts ? implode('; ', $parts) : 'All records';
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Units', 'value' => number_format($this->totalUnits())],
            ['label' => 'Total Owed to Suppliers', 'value' => 'Rs '.number_format($this->totalOwed(), 2)],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Date', 'Product', 'Vendor', 'Quantity', 'Remaining', 'Cost Price', 'Selling Price', 'Payment', 'Owed', 'Note', 'Entered By'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest('stock_date')->latest('id')->get()->map(function (StockIn $entry) use ($forExcel) {
            $product = TableExportService::sanitizeCell($entry->product_name);
            $vendor = TableExportService::sanitizeCell($entry->vendor?->name ?? '—');
            $status = $entry->isCancelled() ? 'Cancelled' : $entry->payment_status->label();
            $note = TableExportService::sanitizeCell($entry->note ?? '');
            $enteredBy = TableExportService::sanitizeCell($entry->user?->name ?? '—');
            $costPrice = $entry->cost_price !== null ? (float) $entry->cost_price : null;
            $sellingPrice = $entry->selling_price !== null ? (float) $entry->selling_price : null;

            return $forExcel
                ? [TableExport::excelDate($entry->stock_date), $product, $vendor, (int) $entry->quantity, (int) $entry->quantity_remaining, $costPrice, $sellingPrice, $status, (float) $entry->amountOwed(), $note, $enteredBy]
                : [$entry->stock_date->format('d M Y'), $product, $vendor, (string) $entry->quantity, (string) $entry->quantity_remaining, $costPrice !== null ? 'Rs '.number_format($costPrice, 2) : '—', $sellingPrice !== null ? 'Rs '.number_format($sellingPrice, 2) : '—', $status, $entry->amountOwed() > 0 ? 'Rs '.number_format($entry->amountOwed(), 2) : '—', $note ?: '—', $enteredBy];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['date', 'string', 'string', 'integer', 'integer', 'currency', 'currency', 'string', 'currency', 'string', 'string'];
    }
}
