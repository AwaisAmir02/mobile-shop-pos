<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class ServiceReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $serviceCategoryId = '',
        protected string $serviceItemId = '',
        protected string $paymentStatusFilter = '',
        protected bool $includeCancelled = false,
    ) {}

    public function query(): Builder
    {
        $query = ServicePayment::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->serviceCategoryId, fn ($query) => $query->where('service_category_id', $this->serviceCategoryId))
            ->when($this->serviceItemId, fn ($query) => $query->where('service_item_id', $this->serviceItemId))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));

        if ($this->includeCancelled) {
            $query->withoutGlobalScope(ExcludesCancelledScope::class);
        }

        return $query;
    }

    /** Totals always exclude cancelled payments, regardless of $includeCancelled. */
    public function totalCollected(): float
    {
        return (float) $this->query()->where('service_payments.status', '!=', 'cancelled')->sum('amount_paid');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) ServicePayment::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (ServicePayment $payment) => $payment->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->serviceCategoryId) {
            $parts[] = 'Category: '.(ServiceCategory::find($this->serviceCategoryId)?->name ?? 'Unknown');
        }

        if ($this->serviceItemId) {
            $parts[] = 'Item: '.(ServiceItem::find($this->serviceItemId)?->name ?? 'Unknown');
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
        return ['Receipt', 'Date', 'Category', 'Item', 'Customer', 'Amount', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with(['serviceCategory', 'serviceItem', 'customer'])->latest()->get()
            ->map(function (ServicePayment $payment) use ($forExcel) {
                $customer = TableExportService::sanitizeCell($payment->customer?->name ?? 'Walk-in');
                $status = $payment->isCancelled() ? 'Cancelled' : $payment->payment_status->label();

                return $forExcel
                    ? [$payment->receiptNumber(), TableExport::excelDate($payment->created_at), $payment->serviceCategory?->name, $payment->serviceItem?->name, $customer, (float) $payment->amount, $status, (float) $payment->amountOwed()]
                    : [$payment->receiptNumber(), $payment->created_at->format('d M Y, h:i A'), $payment->serviceCategory?->name ?? '—', $payment->serviceItem?->name ?? '—', $customer, 'Rs '.number_format($payment->amount, 2), $status, $payment->amountOwed() > 0 ? 'Rs '.number_format($payment->amountOwed(), 2) : '—'];
            })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'currency', 'string', 'currency'];
    }
}
