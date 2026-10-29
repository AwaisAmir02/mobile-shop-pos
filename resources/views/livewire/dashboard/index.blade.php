<?php

use App\Models\Sale;
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

        // A running balance, like Udhaar's own Total Outstanding — deliberately
        // not scoped to the dashboard's period selector, which describes sales
        // activity in a window, not which sales still owe money right now.
        $totalSalesOutstanding = Sale::query()
            ->withSum('payments', 'amount')
            ->get()
            ->sum(fn (Sale $sale) => $sale->amountDue());

        return [
            'periodLabel' => $this->periodLabel(),
            'chart' => $this->buildTrendChart($trend),
            'totalSalesOutstanding' => $totalSalesOutstanding,
            ...$summary,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Dashboard</h1>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-end gap-4">
        <div>
            <x-ui.label value="Period" />
            <div class="mt-1 inline-flex rounded-md border border-slate-300 bg-white p-0.5 shadow-sm">
                @foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $type => $typeLabel)
                    <button
                        type="button"
                        wire:click="setPeriodType('{{ $type }}')"
                        @if ($periodType === $type) aria-pressed="true" @endif
                        @class([
                            'rounded px-3 py-1.5 text-sm font-medium transition-colors',
                            'bg-brand-600 text-white' => $periodType === $type,
                            'text-slate-600 hover:bg-slate-100 hover:text-slate-900' => $periodType !== $type,
                        ])
                    >
                        {{ $typeLabel }}
                    </button>
                @endforeach
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

    {{-- Headline figures. --}}
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat tone="brand" label="Net (Revenue − Expenses)" value="Rs {{ number_format($netSummary, 2) }}" :sub="$periodLabel" />
        <x-ui.stat label="Sales Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" :sub="$periodLabel" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($totalSalesOutstanding, 2) }}" sub="Across all unpaid/partial sales" />
        <x-ui.stat label="Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" :sub="$periodLabel" />
    </div>

    <div class="mt-4 grid grid-cols-1 items-start gap-4 xl:grid-cols-3">
        <x-ui.card title="Revenue Trend" :description="'Sales revenue by '.($periodType === 'year' ? 'year' : ($periodType === 'month' ? 'month' : 'day')).', up to '.$periodLabel" class="xl:col-span-2">
            @php
                $step = count($chart['points']) > 1
                    ? ($chart['width'] - $chart['padding']['left'] - $chart['padding']['right']) / (count($chart['points']) - 1)
                    : $chart['width'] - $chart['padding']['left'] - $chart['padding']['right'];
            @endphp
            <div
                x-data="{ point: null }"
                x-on:mouseleave="point = null"
                class="relative"
            >
                <svg viewBox="0 0 {{ $chart['width'] }} {{ $chart['height'] }}" class="h-auto w-full overflow-visible" role="img" aria-label="Revenue trend chart">
                    @foreach ($chart['gridlines'] as $gridline)
                        <line x1="{{ $chart['padding']['left'] }}" y1="{{ $gridline['y'] }}" x2="{{ $chart['width'] - $chart['padding']['right'] }}" y2="{{ $gridline['y'] }}" stroke="#e2e8f0" stroke-width="1" />
                        <text x="{{ $chart['padding']['left'] - 10 }}" y="{{ $gridline['y'] + 4 }}" text-anchor="end" class="fill-slate-400" font-size="11">Rs {{ $gridline['label'] }}</text>
                    @endforeach

                    @foreach ($chart['points'] as $i => $point)
                        @if ($i % $chart['labelEvery'] === 0 || $i === count($chart['points']) - 1)
                            <text x="{{ $point['x'] }}" y="{{ $chart['height'] - 6 }}" text-anchor="middle" class="fill-slate-400" font-size="11">{{ $point['label'] }}</text>
                        @endif
                    @endforeach

                    <path d="{{ $chart['areaPath'] }}" fill="#0033a9" opacity="0.1" />
                    <path d="{{ $chart['linePath'] }}" fill="none" stroke="#0033a9" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                    @php $last = end($chart['points']); @endphp
                    <circle cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="4" fill="#0033a9" stroke="white" stroke-width="2" />
                    <text x="{{ $last['x'] }}" y="{{ $last['y'] - 10 }}" text-anchor="end" class="fill-slate-700" font-size="11" font-weight="600">
                        Rs {{ $last['value'] >= 1000 ? number_format($last['value'] / 1000, 1).'K' : number_format($last['value'], 0) }}
                    </text>

                    {{-- Hover marker. Plain SVG nodes toggled with x-show: <template> inside <svg>
                         has no .content, which is what broke Alpine's x-for/x-if here before. --}}
                    <g class="pointer-events-none" x-show="point" style="display: none">
                        <line :x1="point?.x ?? 0" :x2="point?.x ?? 0" y1="{{ $chart['padding']['top'] }}" y2="{{ $chart['baselineY'] }}" stroke="#94a3b8" stroke-width="1" />
                        <circle :cx="point?.x ?? 0" :cy="point?.y ?? 0" r="5" fill="#0033a9" stroke="white" stroke-width="2" />
                    </g>

                    {{-- One full-height hover/focus band per point, rendered server-side. Each band
                         carries its own point, so the tooltip can't go stale when Livewire re-renders
                         the chart for another period (Alpine state survives the morph). --}}
                    @foreach ($chart['points'] as $i => $point)
                        <rect
                            x="{{ round($point['x'] - $step / 2, 1) }}" y="{{ $chart['padding']['top'] }}"
                            width="{{ round($step, 1) }}" height="{{ $chart['baselineY'] - $chart['padding']['top'] }}"
                            fill="transparent" class="cursor-pointer focus:outline-none"
                            tabindex="0" aria-label="{{ $point['label'] }}: Rs {{ number_format($point['value'], 2) }}"
                            x-on:mouseenter="point = @js($point)" x-on:focus="point = @js($point)" x-on:blur="point = null"
                        />
                    @endforeach
                </svg>

                <div
                    x-show="point" x-cloak
                    class="pointer-events-none absolute rounded-md bg-brand-950 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
                    :style="point ? `left:` + (point.x / {{ $chart['width'] }} * 100) + `%; top:` + (point.y / {{ $chart['height'] }} * 100) + `%; transform: translate(-50%, -140%)` : ''"
                >
                    <span x-text="point ? point.label : ''"></span>:
                    <span class="font-semibold" x-text="point ? 'Rs ' + Number(point.value).toLocaleString() : ''"></span>
                </div>
            </div>
        </x-ui.card>

        {{-- Everything else, as a compact list. Each row keeps its original screen gate. --}}
        <x-ui.card title="Services & counters" :description="$periodLabel" :padding="false">
            <dl class="divide-y divide-slate-100">
                @php
                    $row = 'flex items-start justify-between gap-4 px-4 py-2.5 sm:px-5';
                    $term = 'text-sm text-slate-600';
                    $figure = 'whitespace-nowrap text-right text-sm font-semibold tabular-nums text-slate-900';
                    $note = 'mt-0.5 text-xs text-slate-400';
                @endphp

                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Items Sold<p class="{{ $note }}">{{ $categories->firstWhere('slug', 'mobile')['units'] ?? 0 }} mobile · {{ $categories->firstWhere('slug', 'accessory')['units'] ?? 0 }} accessory · {{ $categories->firstWhere('slug', 'sim')['units'] ?? 0 }} SIM</p></dt>
                    <dd class="{{ $figure }}">{{ number_format($totalItemsSold) }}</dd>
                </div>
                @unless (auth()->user()->shop?->isScreenHidden('balance-loads'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Balance Loaded</dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalBalanceLoaded, 2) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Balance Load Fees<p class="{{ $note }}">Net service revenue</p></dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalBalanceLoadFees, 2) }}</dd>
                </div>
                @endunless
                @unless (auth()->user()->shop?->isScreenHidden('wallet-loads'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Wallet Cash In</dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalWalletCashInAmount, 2) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Wallet Cash Out</dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalWalletCashOutAmount, 2) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Wallet Load Fees<p class="{{ $note }}">Net service revenue, both directions</p></dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalWalletLoadFees, 2) }}</dd>
                </div>
                @endunless
                @unless (auth()->user()->shop?->isScreenHidden('sim-sales'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">SIM Sales<p class="{{ $note }}">Rs {{ number_format($totalSimSaleRevenue, 2) }} revenue</p></dt>
                    <dd class="{{ $figure }}">{{ number_format($totalSimSalesSold) }}</dd>
                </div>
                @endunless
                @unless (auth()->user()->shop?->isScreenHidden('bills'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Bills Collected</dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalBillsCollected, 2) }}</dd>
                </div>
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Bills Fee Revenue<p class="{{ $note }}">Net service revenue</p></dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalBillsFeeRevenue, 2) }}</dd>
                </div>
                @endunless
                @unless (auth()->user()->shop?->isScreenHidden('repairs'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">Repairs Revenue</dt>
                    <dd class="{{ $figure }}">Rs {{ number_format($totalRepairsRevenue, 2) }}</dd>
                </div>
                @endunless
                @unless (auth()->user()->shop?->isScreenHidden('nadra-verifications'))
                <div class="{{ $row }}">
                    <dt class="{{ $term }}">NADRA Verifications<p class="{{ $note }}">Rs {{ number_format($totalNadraRevenue, 2) }} revenue</p></dt>
                    <dd class="{{ $figure }}">{{ number_format($totalNadraVerifications) }}</dd>
                </div>
                @endunless
            </dl>
        </x-ui.card>
    </div>
</div>
