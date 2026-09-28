<?php

namespace App\ReportQueries;

use App\Exports\TableExport;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class SalesReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
    ) {}

    public function query(): Builder
    {
        return Sale::query()
            ->withCount('items')
            ->withSum('payments', 'amount')
            ->with('customer')
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to));
    }

    public function totalRevenue(): float
    {
        return (float) $this->query()->sum('total');
    }

    /**
     * Invoice-level plus item-level discounts, scoped to the same date
     * range as everything else on this report — mirrors the formula
     * ShopReportService::summary() uses for its own period-scoped figure,
     * just re-scoped to this screen's own from/to filter instead of the
     * day/month period selector.
     */
    public function totalDiscount(): float
    {
        $invoiceDiscount = (float) $this->query()->sum('discount_amount');

        $itemDiscount = (float) SaleItem::query()
            ->whereHas('sale', fn ($query) => $query
                ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
                ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to)))
            ->sum('discount_amount');

        return $invoiceDiscount + $itemDiscount;
    }

    /** A running balance, like Udhaar's own Total Outstanding — deliberately not scoped to the from/to filters above. */
    public function totalOutstanding(): float
    {
        return (float) Sale::query()
            ->withSum('payments', 'amount')
            ->get()
            ->sum(fn (Sale $sale) => $sale->amountDue());
    }

    public function filtersSummary(): string
    {
        if (! $this->from && ! $this->to) {
            return 'All records';
        }

        $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
        $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';

        return "Date range: {$from} – {$to}";
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Revenue', 'value' => 'Rs '.number_format($this->totalRevenue(), 2)],
            ['label' => 'Total Discount Given', 'value' => 'Rs '.number_format($this->totalDiscount(), 2)],
            ['label' => 'Total Outstanding', 'value' => 'Rs '.number_format($this->totalOutstanding(), 2), 'sub' => 'Across all customers who currently owe money'],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Invoice', 'Date', 'Customer', 'Items', 'Total', 'Paid', 'Status'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest()->get()->map(function (Sale $sale) use ($forExcel) {
            $customer = TableExportService::sanitizeCell($sale->customer?->name ?? 'Walk-in');
            $status = ucfirst($sale->paymentStatus());

            return $forExcel
                ? [$sale->invoiceNumber(), TableExport::excelDate($sale->created_at), $customer, (int) $sale->items_count, (float) $sale->total, (float) $sale->amountPaid(), $status]
                : [$sale->invoiceNumber(), $sale->created_at->format('d M Y, h:i A'), $customer, (string) $sale->items_count, 'Rs '.number_format($sale->total, 2), 'Rs '.number_format($sale->amountPaid(), 2), $status];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'integer', 'currency', 'currency', 'string'];
    }
}
