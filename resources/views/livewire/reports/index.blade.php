<?php

use App\Services\ShopReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Reports')] class extends Component
{
    public string $periodType = 'day';
    public string $day = '';
    public string $month = '';

    public function mount(): void
    {
        $this->day = now()->toDateString();
        $this->month = now()->format('Y-m');
    }

    public function setPeriodType(string $type): void
    {
        $this->periodType = $type;
    }

    protected function periodRange(): array
    {
        if ($this->periodType === 'month') {
            $start = Carbon::createFromFormat('Y-m', $this->month)->startOfMonth();
            $end = $start->copy()->endOfMonth();
        } else {
            $start = Carbon::parse($this->day)->startOfDay();
            $end = Carbon::parse($this->day)->endOfDay();
        }

        return [$start, $end];
    }

    protected function periodLabel(): string
    {
        [$start] = $this->periodRange();

        return $this->periodType === 'month'
            ? $start->format('F Y')
            : $start->format('d M Y');
    }

    public function with(ShopReportService $reports): array
    {
        [$start, $end] = $this->periodRange();

        return [
            'periodLabel' => $this->periodLabel(),
            ...$reports->summary(Auth::user()->shop, $start, $end),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Reports</h1>
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
            </div>
        </div>

        @if ($periodType === 'day')
            <x-ui.field label="Date" name="day" for="day">
                <x-ui.input wire:model.live="day" id="day" type="date" />
            </x-ui.field>
        @else
            <x-ui.field label="Month" name="month" for="month">
                <x-ui.input wire:model.live="month" id="month" type="month" />
            </x-ui.field>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <x-ui.stat label="Total Sales Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" />
        <x-ui.stat label="Total Discount Given" value="Rs {{ number_format($totalDiscount, 2) }}" />
        <x-ui.stat label="Total Balance Loaded" value="Rs {{ number_format($totalBalanceLoaded, 2) }}" />
        <x-ui.stat label="Total Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" />
    </div>

    <div class="mt-4 rounded-xl border border-brand-200 bg-brand-50 px-5 py-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-brand-700">Net (Revenue − Expenses)</p>
                <p class="text-xs text-brand-600">{{ $periodLabel }}</p>
            </div>
            <p class="text-display text-brand-900">Rs {{ number_format($netSummary, 2) }}</p>
        </div>
    </div>

    <h2 class="mb-3 mt-8 text-sm font-semibold text-slate-700">Sales by Category</h2>
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($categories as $category)
            <x-ui.stat
                :label="$category['label']"
                value="Rs {{ number_format($category['revenue'], 2) }}"
                :sub="$category['units'].' sold'"
            />
        @endforeach
    </div>
</div>
