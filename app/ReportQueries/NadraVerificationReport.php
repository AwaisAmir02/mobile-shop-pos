<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\NadraVerification;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class NadraVerificationReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return NadraVerification::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function totalCollected(): float
    {
        return (float) $this->query()->sum('total');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) NadraVerification::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (NadraVerification $verification) => $verification->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
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
        return ['Receipt', 'Date', 'Phone', 'CNIC', 'Customer', 'Total', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with('customer')->latest()->get()->map(function (NadraVerification $verification) use ($forExcel) {
            $customer = TableExportService::sanitizeCell($verification->customer?->name ?? 'Walk-in');
            $status = $verification->payment_status->label();

            return $forExcel
                ? [$verification->receiptNumber(), TableExport::excelDate($verification->created_at), $verification->phone_number, $verification->cnic_number, $customer, (float) $verification->total, $status, (float) $verification->amountOwed()]
                : [$verification->receiptNumber(), $verification->created_at->format('d M Y, h:i A'), $verification->phone_number, $verification->cnic_number, $customer, 'Rs '.number_format($verification->total, 2), $status, $verification->amountOwed() > 0 ? 'Rs '.number_format($verification->amountOwed(), 2) : '—'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'currency', 'string', 'currency'];
    }
}
