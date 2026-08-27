<?php

use App\Services\ShopReportService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component
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

    protected function niceMax(float $max): float
    {
        if ($max <= 0) {
            return 100;
        }

        $magnitude = 10 ** floor(log10($max));
        $normalized = $max / $magnitude;

        $niceNormalized = match (true) {
            $normalized <= 1 => 1,
            $normalized <= 2 => 2,
            $normalized <= 5 => 5,
            default => 10,
        };

        return $niceNormalized * $magnitude;
    }

    protected function buildTrendChart(array $trend): array
    {
        $width = 800;
        $height = 260;
        $padding = ['top' => 20, 'right' => 16, 'bottom' => 28, 'left' => 64];

        $chartWidth = $width - $padding['left'] - $padding['right'];
        $chartHeight = $height - $padding['top'] - $padding['bottom'];
        $baselineY = $padding['top'] + $chartHeight;

        $values = array_column($trend, 'value');
        $max = $this->niceMax(max($values));
        $count = count($trend);

        $points = collect($trend)->values()->map(function (array $point, int $i) use ($count, $chartWidth, $chartHeight, $padding, $max) {
            $x = $count > 1
                ? $padding['left'] + ($i * ($chartWidth / ($count - 1)))
                : $padding['left'] + ($chartWidth / 2);
            $y = $padding['top'] + $chartHeight - ($max > 0 ? ($point['value'] / $max) * $chartHeight : 0);

            return ['x' => round($x, 1), 'y' => round($y, 1), 'label' => $point['label'], 'value' => $point['value']];
        })->all();

        $linePath = collect($points)->map(fn ($p, $i) => ($i === 0 ? 'M' : 'L').$p['x'].' '.$p['y'])->implode(' ');
        $areaPath = $linePath.' L'.end($points)['x'].' '.$baselineY.' L'.$points[0]['x'].' '.$baselineY.' Z';

        $gridlines = collect(range(0, 3))->map(function (int $step) use ($max, $padding, $chartHeight) {
            $value = $max * $step / 3;
            $y = $padding['top'] + $chartHeight - ($chartHeight * $step / 3);

            return ['y' => round($y, 1), 'label' => $value >= 1000 ? number_format($value / 1000, 1).'K' : number_format($value, 0)];
        });

        $labelEvery = (int) max(1, ceil($count / 7));

        return [
            'width' => $width,
            'height' => $height,
            'padding' => $padding,
            'baselineY' => $baselineY,
            'points' => $points,
            'linePath' => $linePath,
            'areaPath' => $areaPath,
            'gridlines' => $gridlines,
            'labelEvery' => $labelEvery,
        ];
    }

    public function with(ShopReportService $reports): array
    {
        [$start, $end] = $this->periodRange();
        $summary = $reports->summary(Auth::user()->shop, $start, $end);
        $trend = $reports->revenueTrend(Auth::user()->shop, $this->periodType);

        return [
            'periodLabel' => $this->periodLabel(),
            'chart' => $this->buildTrendChart($trend),
            ...$summary,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Dashboard</h1>
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

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <x-ui.stat label="Sales Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" :sub="$periodLabel" />
        <x-ui.stat label="Items Sold" :value="number_format($totalItemsSold)" sub="{{ $categories->firstWhere('label', 'Mobile Phone')['units'] ?? 0 }} mobile · {{ $categories->firstWhere('label', 'Accessory')['units'] ?? 0 }} accessory · {{ $categories->firstWhere('label', 'SIM / eSIM')['units'] ?? 0 }} SIM" />
        <x-ui.stat label="Balance Loaded" value="Rs {{ number_format($totalBalanceLoaded, 2) }}" />
        <x-ui.stat label="Wallet Loaded" value="Rs {{ number_format($totalWalletLoaded, 2) }}" />
        <x-ui.stat label="Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" />
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

    <x-ui.card title="Revenue Trend" :description="'Sales revenue by '.($periodType === 'year' ? 'year' : ($periodType === 'month' ? 'month' : 'day')).', up to '.$periodLabel" class="mt-6">
        <div
            x-data="{ points: @js($chart['points']), hovered: null }"
            class="relative"
        >
            <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="h-auto w-full" role="img" aria-label="Revenue trend chart">
                @foreach ($chart['gridlines'] as $gridline)
                    <line x1="{{ $chart['padding']['left'] }}" y1="{{ $gridline['y'] }}" x2="{{ $chart['width'] - $chart['padding']['right'] }}" y2="{{ $gridline['y'] }}" stroke="#e2e8f0" stroke-width="1" />
                    <text x="{{ $chart['padding']['left'] - 10 }}" y="{{ $gridline['y'] + 4 }}" text-anchor="end" class="fill-slate-400" font-size="11">Rs {{ $gridline['label'] }}</text>
                @endforeach

                @foreach ($chart['points'] as $i => $point)
                    @if ($i % $chart['labelEvery'] === 0 || $i === count($chart['points']) - 1)
                        <text x="{{ $point['x'] }}" y="{{ $chart['height'] - 6 }}" text-anchor="middle" class="fill-slate-400" font-size="11">{{ $point['label'] }}</text>
                    @endif
                @endforeach

                <path d="{{ $chart['areaPath'] }}" fill="#049669" opacity="0.1" />
                <path d="{{ $chart['linePath'] }}" fill="none" stroke="#049669" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                @php $last = end($chart['points']); @endphp
                <circle cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="4" fill="#049669" stroke="white" stroke-width="2" />
                <text x="{{ $last['x'] }}" y="{{ $last['y'] - 10 }}" text-anchor="end" class="fill-slate-700" font-size="11" font-weight="600">
                    Rs {{ $last['value'] >= 1000 ? number_format($last['value'] / 1000, 1).'K' : number_format($last['value'], 0) }}
                </text>

                <template x-for="(p, i) in points" :key="i">
                    <circle
                        :cx="p.x" :cy="p.y" r="14" fill="transparent"
                        class="cursor-pointer"
                        tabindex="0"
                        x-on:mouseenter="hovered = i" x-on:mouseleave="hovered = null"
                        x-on:focus="hovered = i" x-on:blur="hovered = null"
                    />
                </template>

                <template x-if="hovered !== null">
                    <g class="pointer-events-none">
                        <line :x1="points[hovered].x" :x2="points[hovered].x" y1="{{ $chart['padding']['top'] }}" y2="{{ $chart['baselineY'] }}" stroke="#94a3b8" stroke-width="1" />
                        <circle :cx="points[hovered].x" :cy="points[hovered].y" r="5" fill="#049669" stroke="white" stroke-width="2" />
                    </g>
                </template>
            </svg>

            <div
                x-show="hovered !== null" x-cloak
                class="pointer-events-none absolute rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
                :style="hovered !== null ? `left:` + (points[hovered].x / {{ $chart['width'] }} * 100) + `%; top:` + (points[hovered].y / {{ $chart['height'] }} * 100) + `%; transform: translate(-50%, -140%)` : ''"
            >
                <span x-text="hovered !== null ? points[hovered].label : ''"></span>:
                <span class="font-semibold" x-text="hovered !== null ? 'Rs ' + Number(points[hovered].value).toLocaleString() : ''"></span>
            </div>
        </div>
    </x-ui.card>
</div>
