<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class BillReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $billCategoryId = '',
        protected string $billProviderId = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return BillPayment::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId))
            ->when($this->billProviderId, fn ($query) => $query->where('bill_provider_id', $this->billProviderId))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function totalCollected(): float
    {
        return (float) $this->query()->sum('total');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) BillPayment::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (BillPayment $payment) => $payment->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->billCategoryId) {
            $parts[] = 'Category: '.(BillCategory::find($this->billCategoryId)?->name ?? 'Unknown');
        }

        if ($this->billProviderId) {
            $parts[] = 'Provider: '.(BillProvider::find($this->billProviderId)?->name ?? 'Unknown');
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
        return ['Receipt', 'Date', 'Category', 'Provider', 'Consumer', 'Customer', 'Send From', 'Total', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with(['billCategory', 'billProvider', 'customer', 'shopAccount'])->latest()->get()
            ->map(function (BillPayment $payment) use ($forExcel) {
                $consumer = TableExportService::sanitizeCell($payment->consumer_name).' · '.$payment->consumer_number;
                $customer = TableExportService::sanitizeCell($payment->customer?->name ?? 'Walk-in');
                $status = $payment->payment_status->label();

                return $forExcel
                    ? [$payment->receiptNumber(), TableExport::excelDate($payment->created_at), $payment->billCategory?->name, $payment->billProvider?->name, $consumer, $customer, $payment->shopAccount?->name, (float) $payment->total, $status, (float) $payment->amountOwed()]
                    : [$payment->receiptNumber(), $payment->created_at->format('d M Y, h:i A'), $payment->billCategory?->name ?? '—', $payment->billProvider?->name ?? '—', $consumer, $customer, $payment->shopAccount?->name ?? '—', 'Rs '.number_format($payment->total, 2), $status, $payment->amountOwed() > 0 ? 'Rs '.number_format($payment->amountOwed(), 2) : '—'];
            })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'string', 'string', 'currency', 'string', 'currency'];
    }
}
