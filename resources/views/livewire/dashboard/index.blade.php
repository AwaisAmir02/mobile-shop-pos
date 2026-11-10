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
            'expiryCards' => Auth::user()->shop?->expiry_tracking_enabled && Auth::user()->hasAccessTo('stock-ins') ? (new \App\ReportQueries\ExpiryReport)->summaryCards() : null,
            ...$summary,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Dashboard</x-ui.page-header>
    </x-slot>

    <div class="mb-5 flex flex-wrap items-end gap-4">
        <div>
            <x-ui.label value="Period" />
            <div class="mt-1.5 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1" role="group" aria-label="Period">
                @foreach (['day' => 'Day', 'month' => 'Month', 'year' => 'Year'] as $type => $typeLabel)
                    <button
                        type="button"
                        wire:click="setPeriodType('{{ $type }}')"
                        @if ($periodType === $type) aria-pressed="true" @endif
                        @class([
                            'rounded-full px-4 py-1.5 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link',
                            'bg-surface text-ink shadow-soft ring-1 ring-line' => $periodType === $type,
                            'text-ink-faint hover:text-ink' => $periodType !== $type,
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

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-ui.stat tone="brand" label="Net (Revenue − Expenses)" value="Rs {{ number_format($netSummary, 2) }}" :sub="$periodLabel" />
        <x-ui.stat label="Sales Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" :sub="$periodLabel" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($totalSalesOutstanding, 2) }}" sub="Across all unpaid/partial sales" />
        <x-ui.stat label="Expenses" value="Rs {{ number_format($totalExpenses, 2) }}" :sub="$periodLabel" />
    </div>

    @if ($expiryCards)
        <a href="{{ route('stock-ins.expiry') }}" wire:navigate class="mt-4 block">
            <x-ui.card title="Expiring & Expired Stock" description="Stock on hand today, whatever period is selected above. Open the full list.">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    @foreach ($expiryCards as $card)
                        <div>
                            <p class="text-sm text-ink-faint">{{ $card['label'] }}</p>
                            <p class="text-lg font-semibold text-ink">{{ $card['value'] }}</p>
                            <p class="text-xs text-ink-faint">{{ $card['sub'] }}</p>
                        </div>
                    @endforeach
                </div>
            </x-ui.card>
        </a>
    @endif

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
                        <line x1="{{ $chart['padding']['left'] }}" y1="{{ $gridline['y'] }}" x2="{{ $chart['width'] - $chart['padding']['right'] }}" y2="{{ $gridline['y'] }}" class="stroke-line" stroke-width="1" />
                        <text x="{{ $chart['padding']['left'] - 10 }}" y="{{ $gridline['y'] + 4 }}" text-anchor="end" class="fill-ink-faint" font-size="11">Rs {{ $gridline['label'] }}</text>
                    @endforeach

                    @foreach ($chart['points'] as $i => $point)
                        @if ($i % $chart['labelEvery'] === 0 || $i === count($chart['points']) - 1)
                            <text x="{{ $point['x'] }}" y="{{ $chart['height'] - 6 }}" text-anchor="middle" class="fill-ink-faint" font-size="11">{{ $point['label'] }}</text>
                        @endif
                    @endforeach

                    <path d="{{ $chart['areaPath'] }}" class="fill-primary" opacity="0.1" />
                    <path d="{{ $chart['linePath'] }}" fill="none" class="stroke-primary" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />

                    @php $last = end($chart['points']); @endphp
                    <circle cx="{{ $last['x'] }}" cy="{{ $last['y'] }}" r="4" class="fill-primary stroke-surface" stroke-width="2" />
                    <text x="{{ $last['x'] }}" y="{{ $last['y'] - 10 }}" text-anchor="end" class="fill-ink-muted" font-size="11" font-weight="600">
                        Rs {{ $last['value'] >= 1000 ? number_format($last['value'] / 1000, 1).'K' : number_format($last['value'], 0) }}
                    </text>

                    {{-- Hover marker. Plain SVG nodes toggled with x-show: <template> inside <svg>
                         has no .content, which is what broke Alpine's x-for/x-if here before. --}}
                    <g class="pointer-events-none" x-show="point" style="display: none">
                        <line :x1="point?.x ?? 0" :x2="point?.x ?? 0" y1="{{ $chart['padding']['top'] }}" y2="{{ $chart['baselineY'] }}" class="stroke-line-strong" stroke-width="1" />
                        <circle :cx="point?.x ?? 0" :cy="point?.y ?? 0" r="5" class="fill-primary stroke-surface" stroke-width="2" />
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
                    class="pointer-events-none absolute rounded-lg bg-ink px-2.5 py-1.5 text-xs whitespace-nowrap text-surface shadow-float"
                    :style="point ? `left:` + (point.x / {{ $chart['width'] }} * 100) + `%; top:` + (point.y / {{ $chart['height'] }} * 100) + `%; transform: translate(-50%, -140%)` : ''"
                >
                    <span x-text="point ? point.label : ''"></span>:
                    <span class="font-semibold" x-text="point ? 'Rs ' + Number(point.value).toLocaleString() : ''"></span>
                </div>
            </div>
        </x-ui.card>

        {{-- Everything else, as a compact list. Each row keeps its original screen gate.
             Rows with nothing this period fold behind "Show all"; they stay in the page. --}}
        @php
            $viewerShop = auth()->user()->shop;
            $money = fn ($value) => 'Rs '.number_format($value, 2);
            $counterRows = [
                ['label' => 'Items Sold', 'value' => number_format($totalItemsSold), 'zero' => (float) $totalItemsSold == 0,
                    'note' => ($categories->firstWhere('slug', 'mobile')['units'] ?? 0).' mobile · '.($categories->firstWhere('slug', 'accessory')['units'] ?? 0).' accessory · '.($categories->firstWhere('slug', 'sim')['units'] ?? 0).' SIM',
                    'valueNote' => $soldByUnit ? \App\Enums\QuantityUnit::summarize($soldByUnit) : null],
            ];
            if (! $viewerShop?->isScreenHidden('balance-loads')) {
                $counterRows[] = ['label' => 'Balance Loaded', 'value' => $money($totalBalanceLoaded), 'zero' => (float) $totalBalanceLoaded == 0];
                $counterRows[] = ['label' => 'Balance Load Fees', 'note' => 'Net service revenue', 'value' => $money($totalBalanceLoadFees), 'zero' => (float) $totalBalanceLoadFees == 0];
            }
            if (! $viewerShop?->isScreenHidden('wallet-loads')) {
                $counterRows[] = ['label' => 'Wallet Cash In', 'value' => $money($totalWalletCashInAmount), 'zero' => (float) $totalWalletCashInAmount == 0];
                $counterRows[] = ['label' => 'Wallet Cash Out', 'value' => $money($totalWalletCashOutAmount), 'zero' => (float) $totalWalletCashOutAmount == 0];
                $counterRows[] = ['label' => 'Wallet Load Fees', 'note' => 'Net service revenue, both directions', 'value' => $money($totalWalletLoadFees), 'zero' => (float) $totalWalletLoadFees == 0];
            }
            if (! $viewerShop?->isScreenHidden('sim-sales')) {
                $counterRows[] = ['label' => 'SIM Sales', 'note' => $money($totalSimSaleRevenue).' revenue', 'value' => number_format($totalSimSalesSold), 'zero' => (float) $totalSimSalesSold == 0];
            }
            if (! $viewerShop?->isScreenHidden('bills')) {
                $counterRows[] = ['label' => 'Bills Collected', 'value' => $money($totalBillsCollected), 'zero' => (float) $totalBillsCollected == 0];
                $counterRows[] = ['label' => 'Bills Fee Revenue', 'note' => 'Net service revenue', 'value' => $money($totalBillsFeeRevenue), 'zero' => (float) $totalBillsFeeRevenue == 0];
            }
            if (! $viewerShop?->isScreenHidden('repairs')) {
                $counterRows[] = ['label' => 'Repairs Revenue', 'value' => $money($totalRepairsRevenue), 'zero' => (float) $totalRepairsRevenue == 0];
            }
            if (! $viewerShop?->isScreenHidden('nadra-verifications')) {
                $counterRows[] = ['label' => 'NADRA Verifications', 'note' => $money($totalNadraRevenue).' revenue', 'value' => number_format($totalNadraVerifications), 'zero' => (float) $totalNadraVerifications == 0];
            }
            $quietRows = count(array_filter($counterRows, fn ($row) => $row['zero']));
        @endphp
        <x-ui.card title="Services & counters" :description="$periodLabel" :padding="false">
            <div x-data="{ showAll: false }">
                <dl class="divide-y divide-line">
                    @foreach ($counterRows as $counterRow)
                        <div
                            class="flex items-start justify-between gap-4 px-4 py-2.5 sm:px-5"
                            {!! $counterRow['zero'] ? 'x-show="showAll" x-cloak data-quiet' : '' !!}
                        >
                            <dt class="text-sm text-ink-muted">
                                {{ $counterRow['label'] }}
                                @if ($counterRow['note'] ?? null)
                                    <p class="mt-0.5 text-xs text-ink-faint">{{ $counterRow['note'] }}</p>
                                @endif
                            </dt>
                            <dd class="whitespace-nowrap text-right text-sm font-semibold tabular-nums text-ink">
                                {{ $counterRow['value'] }}
                                @if ($counterRow['valueNote'] ?? null)
                                    <p class="mt-0.5 text-xs font-normal text-ink-faint">{{ $counterRow['valueNote'] }}</p>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if ($quietRows > 0)
                    <button
                        type="button"
                        x-on:click="showAll = ! showAll"
                        :aria-expanded="showAll.toString()"
                        class="flex w-full items-center justify-center gap-1.5 border-t border-line px-4 py-2.5 text-xs font-semibold text-ink-faint transition-colors hover:bg-surface-sunken hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-link"
                    >
                        <span x-show="! showAll">Show {{ $quietRows }} with nothing this period</span>
                        <span x-show="showAll" x-cloak>Hide the empty ones</span>
                    </button>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
