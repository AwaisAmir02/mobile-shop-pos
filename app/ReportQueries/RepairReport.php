<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\MainCategory;
use App\Models\Repair;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class RepairReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $category = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return Repair::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->category, fn ($query) => $query->where('category', $this->category))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function totalCollected(): float
    {
        return (float) $this->query()->sum('total');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) Repair::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (Repair $repair) => $repair->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->category) {
            $parts[] = 'Category: '.(MainCategory::where('slug', $this->category)->value('name') ?? $this->category);
        }

        if ($this->paymentStatusFilter) {
            $parts[] = 'Payment Status: '.PaymentStatus::from($this->paymentStatusFilter)->label();
        }

        return $parts ? implode('; ', $parts) : 'All records';
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Collected', 'value' => 'Rs '.number_format($this->totalCollected(), 2)],
            ['label' => 'Total Owed by Customers', 'value' => 'Rs '.number_format($this->totalOwed(), 2)],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Receipt', 'Date', 'Category', 'Description', 'Customer', 'Total', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with(['customer', 'mainCategory'])->latest()->get()->map(function (Repair $repair) use ($forExcel) {
            $description = TableExportService::sanitizeCell($repair->description);
            $customer = TableExportService::sanitizeCell($repair->customer?->name ?? 'Walk-in');
            $status = $repair->payment_status->label();

            return $forExcel
                ? [$repair->receiptNumber(), TableExport::excelDate($repair->created_at), $repair->categoryLabel(), $description, $customer, (float) $repair->total, $status, (float) $repair->amountOwed()]
                : [$repair->receiptNumber(), $repair->created_at->format('d M Y, h:i A'), $repair->categoryLabel(), $description, $customer, 'Rs '.number_format($repair->total, 2), $status, $repair->amountOwed() > 0 ? 'Rs '.number_format($repair->amountOwed(), 2) : '—'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'currency', 'string', 'currency'];
    }
}
