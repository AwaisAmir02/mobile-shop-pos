<?php

namespace App\ReportQueries;

use App\Enums\PaymentStatus;
use App\Exports\TableExport;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class WalletLoadReport implements ScreenReport
{
    public function __construct(
        protected string $tab = 'cash_in',
        protected string $from = '',
        protected string $to = '',
        protected string $provider = '',
        protected string $shopAccountId = '',
        protected string $paymentStatusFilter = '',
    ) {}

    public function query(): Builder
    {
        return WalletLoad::query()
            ->where('direction', $this->tab)
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->provider, fn ($query) => $query->where('provider', $this->provider))
            ->when($this->shopAccountId, fn ($query) => $query->where('shop_account_id', $this->shopAccountId))
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

    /**
     * Deliberately unfiltered by date/provider/account (a running balance,
     * not scoped to which rows are currently listed), but IS scoped to the
     * active tab: Cash Out's shortfall is a shop liability, not a customer
     * receivable, and must never be summed together with Cash In's.
     */
    public function totalOwed(): float
    {
        return (float) WalletLoad::query()
            ->where('direction', $this->tab)
            ->where('payment_status', '!=', PaymentStatus::Paid->value)
            ->get()
            ->sum(fn (WalletLoad $load) => $load->amountOwed());
    }

    public function providerTotals()
    {
        return $this->query()
            ->selectRaw('provider, SUM(amount) as total')
            ->groupBy('provider')
            ->orderByDesc('total')
            ->get();
    }

    public function filtersSummary(): string
    {
        $parts = [$this->tab === 'cash_in' ? 'Cash In' : 'Cash Out'];

        if ($this->from || $this->to) {
            $from = $this->from ? \Carbon\Carbon::parse($this->from)->format('d M Y') : 'earliest';
            $to = $this->to ? \Carbon\Carbon::parse($this->to)->format('d M Y') : 'latest';
            $parts[] = "Date range: {$from} – {$to}";
        }

        if ($this->provider) {
            $parts[] = 'Provider: '.$this->provider;
        }

        if ($this->shopAccountId) {
            $parts[] = 'Shop Account: '.(ShopAccount::find($this->shopAccountId)?->name ?? 'Unknown');
        }

        if ($this->paymentStatusFilter) {
            $parts[] = 'Payment Status: '.PaymentStatus::from($this->paymentStatusFilter)->label();
        }

        return implode('; ', $parts);
    }

    public function summaryCards(): array
    {
        return [
            ['label' => $this->tab === 'cash_in' ? 'Total Loaded' : 'Total Given', 'value' => 'Rs '.number_format($this->totalLoaded(), 2)],
            ['label' => 'Net Service Fees', 'value' => 'Rs '.number_format($this->totalFees(), 2)],
            ['label' => $this->tab === 'cash_in' ? 'Total Owed by Customers' : 'Total Owed to Customers', 'value' => 'Rs '.number_format($this->totalOwed(), 2)],
        ];
    }

    public function tableHeaders(): array
    {
        return $this->tab === 'cash_in'
            ? ['Receipt', 'Date', 'Provider', 'Recipient', 'Amount', 'Fee', 'Discount', 'Total', 'Shop Account', 'Payment', 'Owed']
            : ['Receipt', 'Date', 'Provider', 'Customer', 'Amount', 'Fee', 'Discount', 'Total', 'Shop Account', 'Payment', 'Owed'];
    }

    public function tableRows(bool $forExcel): array
    {
        $shopAccountsById = ShopAccount::query()->get()->keyBy('id');

        return $this->query()->latest()->get()->map(function (WalletLoad $load) use ($forExcel, $shopAccountsById) {
            $recipient = $this->tab === 'cash_in'
                ? TableExportService::sanitizeCell($load->customer?->name ?? $load->account_name ?? '—')
                : TableExportService::sanitizeCell($load->customer?->name ?? 'Walk-in');
            $shopAccount = $shopAccountsById->get($load->shop_account_id)?->name;
            $status = $load->payment_status->label();

            return $forExcel
                ? [$load->receiptNumber(), TableExport::excelDate($load->created_at), $load->provider, $recipient, (float) $load->amount, (float) $load->fee, (float) $load->discount, (float) $load->total, $shopAccount, $status, (float) $load->amountOwed()]
                : [$load->receiptNumber(), $load->created_at->format('d M Y, h:i A'), $load->provider, $recipient, 'Rs '.number_format($load->amount, 2), 'Rs '.number_format($load->fee, 2), 'Rs '.number_format($load->discount, 2), 'Rs '.number_format($load->total, 2), $shopAccount ?? '—', $status, $load->amountOwed() > 0 ? 'Rs '.number_format($load->amountOwed(), 2) : '—'];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'date', 'string', 'string', 'currency', 'currency', 'currency', 'currency', 'string', 'string', 'currency'];
    }
}
