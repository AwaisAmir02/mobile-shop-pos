<?php

use App\Models\DeliveryRoute;
use App\Models\User;
use App\ReportQueries\SalesPerformance;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/** Sales by booker, by route and by brand (W8): the figures are App\ReportQueries\SalesPerformance. */
new #[Layout('layouts.app')] #[Title('Sales by Booker & Route')] class extends Component
{
    #[Url(except: 'booker')]
    public string $by = 'booker';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    #[Url(except: '')]
    public string $bookerId = '';

    #[Url(except: '')]
    public string $routeId = '';

    public function mount(): void
    {
        $this->by = in_array($this->by, SalesPerformance::GROUPINGS, true) ? $this->by : 'booker';
        $this->from = $this->from ?: now()->parse(BatchExpiry::today())->startOfMonth()->toDateString();
        $this->to = $this->to ?: BatchExpiry::today();
    }

    public function with(): array
    {
        $rows = (new SalesPerformance(
            Auth::user()->shop_id, $this->by, $this->from, $this->to,
            $this->bookerId !== '' ? (int) $this->bookerId : null,
            $this->routeId !== '' ? (int) $this->routeId : null,
        ))->rows();

        return [
            'rows' => $rows,
            'totals' => SalesPerformance::totals($rows),
            'deliveryRoutes' => DeliveryRoute::query()->orderBy('name')->get(),
            'users' => User::query()->where('shop_id', Auth::user()->shop_id)->orderBy('name')->get(),
            'canSeeCost' => Auth::user()->hasAccessTo('sales-view-cost-price'),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('reports.index') }}" back-label="Reports" subtitle="Who sold what, on which route, and which brands, with gross profit">Sales by Booker & Route</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="($bookerId !== '' ? 1 : 0) + ($routeId !== '' ? 1 : 0)">
        <x-ui.field label="By" for="perfBy" class="w-full sm:w-auto">
            <x-ui.segmented name="perf-by" wire:model.live="by" :options="['booker' => 'Booker', 'route' => 'Route', 'brand' => 'Brand']" label="By" />
        </x-ui.field>
        <x-ui.field label="From" for="perfFrom" class="sm:max-w-[11rem]">
            <x-ui.input wire:model.live="from" id="perfFrom" type="date" />
        </x-ui.field>
        <x-ui.field label="To" for="perfTo" class="sm:max-w-[11rem]">
            <x-ui.input wire:model.live="to" id="perfTo" type="date" />
        </x-ui.field>
        <x-ui.field label="Booker" for="perfBooker" class="w-full sm:w-44">
            <x-ui.select wire:model.live="bookerId" id="perfBooker">
                <option value="">Everyone</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Route" for="perfRoute" class="w-full sm:w-44">
            <x-ui.select wire:model.live="routeId" id="perfRoute">
                <option value="">All routes</option>
                @foreach ($deliveryRoutes as $deliveryRoute)
                    <option value="{{ $deliveryRoute->id }}">{{ $deliveryRoute->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </x-ui.filter-bar>

    @if ($rows->isEmpty())
        <x-ui.empty-state title="No sales in these dates" description="Try other dates or filters." />
    @else
        <x-ui.table stack :headers="array_merge([['booker' => 'Booker', 'route' => 'Route', 'brand' => 'Brand'][$by], 'Sales', 'Revenue'], $canSeeCost ? ['Cost', 'Gross profit', 'Margin'] : [])" data-performance="{{ $by }}">
            @foreach ($rows as $row)
                <x-ui.table-row wire:key="perf-{{ $by }}-{{ $loop->index }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $row['name'] }}</x-ui.table-cell>
                    <x-ui.table-cell label="Sales" class="tabular-nums">{{ $row['sales'] }}</x-ui.table-cell>
                    <x-ui.table-cell label="Revenue" class="tabular-nums">Rs {{ number_format($row['revenue'], 2) }}</x-ui.table-cell>
                    @if ($canSeeCost)
                        <x-ui.table-cell label="Cost" class="tabular-nums">Rs {{ number_format($row['cost'], 2) }}{{ $row['noCost'] ? ' *' : '' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Gross profit" class="font-semibold tabular-nums text-ink">Rs {{ number_format($row['profit'], 2) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Margin" class="tabular-nums">{{ $row['margin'] !== null ? $row['margin'].'%' : '—' }}</x-ui.table-cell>
                    @endif
                </x-ui.table-row>
            @endforeach
            <x-ui.table-row>
                <x-ui.table-cell primary class="font-bold text-ink">Total</x-ui.table-cell>
                <x-ui.table-cell label="Sales" class="font-bold tabular-nums">{{ $totals['sales'] }}</x-ui.table-cell>
                <x-ui.table-cell label="Revenue" class="font-bold tabular-nums">Rs {{ number_format($totals['revenue'], 2) }}</x-ui.table-cell>
                @if ($canSeeCost)
                    <x-ui.table-cell label="Cost" class="font-bold tabular-nums">Rs {{ number_format($totals['cost'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Gross profit" class="font-bold tabular-nums">Rs {{ number_format($totals['profit'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Margin"></x-ui.table-cell>
                @endif
            </x-ui.table-row>
        </x-ui.table>
        @if ($canSeeCost && $rows->contains('noCost', true))
            <p class="mt-2 text-xs text-ink-faint">* Some lines have no cost recorded, so their profit is overstated.</p>
        @endif
        <p class="mt-2 text-xs text-ink-faint">Before returns. A sale counts for its customer's route as it is now. Sales made at the counter with no booker show as "Not booked".</p>
    @endif
</div>
