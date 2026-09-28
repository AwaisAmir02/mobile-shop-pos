<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\SimSale;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class SimSaleReport implements ScreenReport
{
    public function __construct(
        protected string $from = '',
        protected string $to = '',
        protected string $network = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return SimSale::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->network, fn ($query) => $query->where('network', $this->network))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function totalRevenue(): float
    {
        return (float) $this->query()->sum('total');
    }

    /** Deliberately unfiltered — a running balance of everything still owed, not scoped to the date range being viewed. */
    public function totalOwed(): float
    {
        return (float) SimSale::query()
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (SimSale $sale) => $sale->amountOwed());
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->network) {
            $parts[] = 'Network: '.$this->network;
        }

        if ($this->paymentStatusFilter) {
            $parts[] = 'Payment Status: '.PaymentStatus::from($this->paymentStatusFilter)->label();
        }

        return $parts ? implode('; ', $parts) : 'All records';
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Revenue', 'value' => 'Rs '.number_format($this->totalRevenue(), 2)],
            ['label' => 'Total Owed by Customers', 'value' => 'Rs '.number_format($this->totalOwed(), 2)],
        ];
    }

    public function tableHeaders(): array
    {
        return ['Receipt', 'Date', 'Network', 'Plan', 'SIM Number', 'Customer', 'Total', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->with('customer')->latest()->get()->map(function (SimSale $sale) use ($forExcel) {
            $plan = $sale->sim_type->label().' · '.$sale->sim_form->label();
            $customer = TableExportService::sanitizeCell($sale->customer?->name ?? 'Walk-in');
            $status = $sale->payment_status->label();

            return $forExcel
                ? [$sale->receiptNumber(), TableExport::excelDate($sale->created_at), $sale->network, $plan, $sale->sim_number, $customer, (float) $sale->total, $status, (float) $sale->amountOwed()]
                : [$sale->receiptNumber(), $sale->created_at->format('d M Y, h:i A'), $sale->network, $plan, $sale->sim_number, $customer, 'Rs '.number_format($sale->total, 2), $status, $sale->amountOwed() > 0 ? 'Rs '.number_format($sale->amountOwed(), 2) : '—'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'string', 'string', 'currency', 'string', 'currency'];
    }
}
