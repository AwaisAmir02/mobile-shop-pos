<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\BalanceLoad;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class BalanceLoadReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return BalanceLoad::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function totalLoaded(): float
    {
        return (float) $this->query()->sum('amount');
    }

    public function totalFees(): float
    {
        return (float) $this->query()->sum('fee') - (float) $this->query()->sum('discount');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) BalanceLoad::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (BalanceLoad $load) => $load->amountOwed());
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
            ['label' => 'Total Loaded', 'value' => 'Rs '.number_format($this->totalLoaded(), 2)],
            ['label' => 'Total Fees', 'value' => 'Rs '.number_format($this->totalFees(), 2)],
            ['label' => 'Total Owed by Customers', 'value' => 'Rs '.number_format($this->totalOwed(), 2)],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Receipt', 'Date', 'Network', 'Type', 'Phone', 'Customer', 'Amount', 'Fee', 'Discount', 'Total', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with('customer')->latest()->get()->map(function (BalanceLoad $load) use ($forExcel) {
            $customer = TableExportService::sanitizeCell($load->customer?->name ?? 'Walk-in');
            $status = $load->payment_status->label();

            return $forExcel
                ? [$load->receiptNumber(), TableExport::excelDate($load->created_at), $load->network, $load->load_type->label(), $load->phone_number, $customer, (float) $load->amount, (float) $load->fee, (float) $load->discount, (float) $load->total, $status, (float) $load->amountOwed()]
                : [$load->receiptNumber(), $load->created_at->format('d M Y, h:i A'), $load->network, $load->load_type->label(), $load->phone_number ?? '—', $customer, 'Rs '.number_format($load->amount, 2), 'Rs '.number_format($load->fee, 2), 'Rs '.number_format($load->discount, 2), 'Rs '.number_format($load->total, 2), $status, $load->amountOwed() > 0 ? 'Rs '.number_format($load->amountOwed(), 2) : '—'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'string', 'currency', 'currency', 'currency', 'currency', 'string', 'currency'];
    }
}
