<?php

use App\Models\DeliveryRoute;
use App\Models\SalesOrder;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/** Booked orders (W7): what is waiting to go out, on a van, delivered or cancelled. */
new #[Layout('layouts.app')] #[Title('Orders')] class extends Component
{
    #[Url(except: 'booked')]
    public string $status = 'booked';

    #[Url(except: '')]
    public string $routeId = '';

    #[Url(except: '')]
    public string $bookerId = '';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
    }

    public function with(): array
    {
        return [
            'orders' => SalesOrder::query()
                ->with(['customer', 'booker', 'deliveryRoute', 'loadSheet'])
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->when($this->routeId !== '', fn ($q) => $q->where('route_id', (int) $this->routeId))
                ->when($this->bookerId !== '', fn ($q) => $q->where('booker_id', (int) $this->bookerId))
                ->orderByRaw('delivery_date IS NULL')->orderBy('delivery_date')->latest('id')
                ->limit(300)
                ->get(),
            'deliveryRoutes' => DeliveryRoute::query()->orderBy('name')->get(),
            'users' => User::query()->where('shop_id', Auth::user()->shop_id)->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Orders booked on the route. Their stock is held until delivery.">Orders</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="sales" />

    <x-ui.filter-bar :active="($status !== 'booked' ? 1 : 0) + ($routeId !== '' ? 1 : 0) + ($bookerId !== '' ? 1 : 0)">
        <x-ui.field label="Status" for="orderStatus" class="w-full sm:w-40">
            <x-ui.select wire:model.live="status" id="orderStatus">
                <option value="booked">Booked</option>
                <option value="delivered">Delivered</option>
                <option value="cancelled">Cancelled</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Route" for="orderRoute" class="w-full sm:w-48">
            <x-ui.select wire:model.live="routeId" id="orderRoute">
                <option value="">All routes</option>
                @foreach ($deliveryRoutes as $deliveryRoute)
                    <option value="{{ $deliveryRoute->id }}">{{ $deliveryRoute->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Booker" for="orderBookerFilter" class="w-full sm:w-48">
            <x-ui.select wire:model.live="bookerId" id="orderBookerFilter">
                <option value="">Everyone</option>
                @foreach ($users as $user)
                    <option value="{{ $user->id }}">{{ $user->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-slot:actions>
            <x-ui.button href="{{ route('load-sheets.index') }}" wire:navigate variant="secondary" class="shrink-0">Load Sheets</x-ui.button>
            <x-ui.button href="{{ route('sales-orders.create') }}" wire:navigate class="shrink-0" data-book-order>Book Order</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($orders->isEmpty())
        <x-ui.empty-state title="No orders here" description="Book an order for a customer; it goes out on a load sheet and becomes a sale on delivery." />
    @else
        <x-ui.table stack :headers="['Order', 'Customer', 'Route', 'Deliver on', 'Value', 'Status']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="order-{{ $order->id }}" data-order="{{ $order->id }}">
                    <x-ui.table-cell primary>
                        <a href="{{ route('sales-orders.show', $order) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $order->number() }}</a>
                        <span class="block text-xs text-ink-faint">{{ $order->order_date->format('d M Y') }}{{ $order->booker ? ' · '.$order->booker->name : '' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Customer">{{ $order->customer?->displayName() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Route">{{ $order->deliveryRoute?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Deliver on">{{ $order->delivery_date?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Value" class="tabular-nums">Rs {{ number_format($order->estimated_total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="match ($order->status) { \App\Enums\SalesOrderStatus::Delivered => 'success', \App\Enums\SalesOrderStatus::Cancelled => 'default', default => $order->load_sheet_id ? 'brand' : 'warning' }">{{ $order->statusLabel() }}</x-ui.badge>
                        @if ($order->loadSheet)
                            <span class="block text-xs text-ink-faint">{{ $order->loadSheet->number() }}</span>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
