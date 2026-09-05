<?php

use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Sale;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Services\ShopReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Party Ledger')] class extends Component
{
    public string $periodType = 'day';
    public string $day = '';
    public string $month = '';
    public string $year = '';

    public function mount(): void
    {
        $this->day = now()->toDateString();
        $this->month = now()->format('Y-m');
        $this->year = now()->format('Y');
    }

    public function setPeriodType(string $type): void
    {
        $this->periodType = $type;
    }

    protected function periodRange(): array
    {
        return match ($this->periodType) {
            'month' => [
                Carbon::createFromFormat('Y-m', $this->month)->startOfMonth(),
                Carbon::createFromFormat('Y-m', $this->month)->endOfMonth(),
            ],
            'year' => [
                Carbon::createFromFormat('Y', $this->year)->startOfYear(),
                Carbon::createFromFormat('Y', $this->year)->endOfYear(),
            ],
            default => [
                Carbon::parse($this->day)->startOfDay(),
                Carbon::parse($this->day)->endOfDay(),
            ],
        };
    }

    protected function periodLabel(): string
    {
        [$start] = $this->periodRange();

        return match ($this->periodType) {
            'month' => $start->format('F Y'),
            'year' => $start->format('Y'),
            default => $start->format('d M Y'),
        };
    }

    /**
     * Sales-outstanding and Udhaar-outstanding are two different kinds of
     * money owed and must never be combined or netted against each other —
     * every row below carries them as two separate figures, matching the
     * same separation already enforced on the customer profile screen.
     */
    public function with(ShopReportService $reports): array
    {
        [$start, $end] = $this->periodRange();

        $summary = $reports->summary(Auth::user()->shop, $start, $end);

        $commission = $summary['totalWalletLoadFees']
            + $summary['totalBillsFeeRevenue']
            + $summary['totalBalanceLoadFees']
            + (float) SimSale::whereBetween('created_at', [$start, $end])->sum('fee')
            + (float) NadraVerification::whereBetween('created_at', [$start, $end])->sum('fee');

        $sales = Sale::query()->withSum('payments', 'amount')->get();
        $salesByCustomer = $sales->whereNotNull('customer_id')->groupBy('customer_id');
        $walkInSalesOutstanding = $sales->whereNull('customer_id')->sum(fn (Sale $sale) => $sale->amountDue());

        $udhaarBalances = UdhaarTransaction::balancesByCustomer();

        $rows = Customer::query()->orderBy('name')->get()->map(function (Customer $customer) use ($salesByCustomer, $udhaarBalances) {
            $customerSales = $salesByCustomer->get($customer->id, collect());
            $udhaarBalance = (float) ($udhaarBalances->get($customer->id) ?? 0);

            return [
                'customer' => $customer,
                'salesOutstanding' => $customerSales->sum(fn (Sale $sale) => $sale->amountDue()),
                'udhaarBalance' => $udhaarBalance,
                'udhaarStatus' => match (true) {
                    $udhaarBalance > 0 => 'due',
                    $udhaarBalance < 0 => 'advance',
                    default => 'settled',
                },
            ];
        });

        return [
            'periodLabel' => $this->periodLabel(),
            'rows' => $rows,
            'walkInSalesOutstanding' => $walkInSalesOutstanding,
            'totalSalesOutstanding' => $rows->sum('salesOutstanding') + $walkInSalesOutstanding,
            'totalUdhaarOutstanding' => $rows->sum(fn (array $row) => max(0.0, $row['udhaarBalance'])),
            'salesRevenue' => $summary['totalRevenue'],
            'commission' => $commission,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Party Ledger</h1>
    </x-slot>

    <div class="mb-6 flex flex-wrap items-end gap-4">
        <div>
            <x-ui.label value="Period" />
            <div class="mt-1.5 inline-flex rounded-lg border border-slate-300 p-1">
                <button
                    type="button"
                    wire:click="setPeriodType('day')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-600 text-white' => $periodType === 'day',
                        'text-slate-600 hover:text-slate-900' => $periodType !== 'day',
                    ])
                >
                    Day
                </button>
                <button
                    type="button"
                    wire:click="setPeriodType('month')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-600 text-white' => $periodType === 'month',
                        'text-slate-600 hover:text-slate-900' => $periodType !== 'month',
                    ])
                >
                    Month
                </button>
                <button
                    type="button"
                    wire:click="setPeriodType('year')"
                    @class([
                        'rounded-md px-3 py-1.5 text-sm font-medium transition',
                        'bg-brand-600 text-white' => $periodType === 'year',
                        'text-slate-600 hover:text-slate-900' => $periodType !== 'year',
                    ])
                >
                    Year
                </button>
            </div>
        </div>

        @if ($periodType === 'day')
            <x-ui.field label="Date" name="day" for="day">
                <x-ui.input wire:model.live="day" id="day" type="date" />
            </x-ui.field>
        @elseif ($periodType === 'month')
            <x-ui.field label="Month" name="month" for="month">
                <x-ui.input wire:model.live="month" id="month" type="month" />
            </x-ui.field>
        @else
            <x-ui.field label="Year" name="year" for="year">
                <x-ui.input wire:model.live="year" id="year" type="number" min="2000" max="2100" class="w-28" />
            </x-ui.field>
        @endif
    </div>

    <h2 class="mb-3 text-sm font-semibold text-slate-700">Earnings — {{ $periodLabel }}</h2>
    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Sales Revenue" value="Rs {{ number_format($salesRevenue, 2) }}" sub="From the Sales module" />
        <x-ui.stat label="Commission" value="Rs {{ number_format($commission, 2) }}" sub="Wallet Load + Bills + Balance Load + SIM Sale + NADRA fees" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($totalSalesOutstanding, 2) }}" sub="Not scoped to the period above" />
        <x-ui.stat label="Udhaar Outstanding" value="Rs {{ number_format($totalUdhaarOutstanding, 2) }}" sub="Separate from Sales — not scoped to the period above" />
    </div>

    <h2 class="mb-3 text-sm font-semibold text-slate-700">Per-Customer Balances</h2>
    <x-ui.table :headers="['Customer', 'Sales Outstanding', 'Udhaar Balance', 'Udhaar Status']">
        <x-ui.table-row>
            <x-ui.table-cell class="font-medium text-slate-900">Walk-in (no customer)</x-ui.table-cell>
            <x-ui.table-cell>Rs {{ number_format($walkInSalesOutstanding, 2) }}</x-ui.table-cell>
            <x-ui.table-cell>—</x-ui.table-cell>
            <x-ui.table-cell>—</x-ui.table-cell>
        </x-ui.table-row>

        @foreach ($rows as $row)
            <x-ui.table-row wire:key="party-{{ $row['customer']->id }}">
                <x-ui.table-cell class="font-medium text-slate-900">
                    <a href="{{ route('customers.show', $row['customer']) }}" wire:navigate class="hover:text-brand-700">
                        {{ $row['customer']->name }}
                    </a>
                </x-ui.table-cell>
                <x-ui.table-cell>Rs {{ number_format($row['salesOutstanding'], 2) }}</x-ui.table-cell>
                <x-ui.table-cell>Rs {{ number_format(abs($row['udhaarBalance']), 2) }}</x-ui.table-cell>
                <x-ui.table-cell>
                    @if ($row['udhaarStatus'] === 'due')
                        <x-ui.badge variant="warning">Due</x-ui.badge>
                    @elseif ($row['udhaarStatus'] === 'advance')
                        <x-ui.badge variant="brand">Advance</x-ui.badge>
                    @else
                        <x-ui.badge variant="success">Settled</x-ui.badge>
                    @endif
                </x-ui.table-cell>
            </x-ui.table-row>
        @endforeach
    </x-ui.table>
</div>
