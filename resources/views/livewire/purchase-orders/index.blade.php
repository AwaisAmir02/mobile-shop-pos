<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Vendor;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Purchase Orders')] class extends Component
{
    use WithPagination;

    public string $statusFilter = '';
    public string $vendorFilter = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['statusFilter', 'vendorFilter'], true)) {
            $this->resetPage();
        }
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;

        return [
            'orders' => PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)
                ->inCurrentBranch()
                ->with(['vendor', 'branch', 'lines.activeGrnLines'])
                ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
                ->when($this->vendorFilter, fn ($q) => $q->where('vendor_id', $this->vendorFilter))
                ->latest('order_date')
                ->latest('id')
                ->paginate(15),
            'vendors' => Vendor::query()->orderBy('name')->get(),
            'enabled' => (bool) $shop?->purchase_orders_enabled,
            'canCreate' => $shop?->purchase_orders_enabled && Auth::user()->hasAccessTo('purchase-orders'),
            'canSeeReceipts' => Auth::user()->hasAccessTo('receive-goods'),
            'showBranch' => BranchContext::current()->hasMultipleBranches(Auth::user()->shop_id),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Purchase Orders</h1>
            <div class="flex items-center gap-4">
                @if ($canSeeReceipts)
                    <a href="{{ route('purchase-orders.receipts') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">Goods Receipts →</a>
                @endif
                @if ($canCreate)
                    <a href="{{ route('purchase-orders.create') }}" wire:navigate><x-ui.button size="sm">New Purchase Order</x-ui.button></a>
                @endif
            </div>
        </div>
    </x-slot>

    @unless ($enabled)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Purchase orders aren't on your plan right now, so these are read-only. Use <a href="{{ route('stock-ins.index') }}" wire:navigate class="font-medium underline">Stock In</a> to receive goods. What you owe vendors isn't affected.
        </div>
    @endunless

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <x-ui.field label="Status" name="statusFilter" for="statusFilter" class="sm:max-w-[12rem]">
            <x-ui.select wire:model.live="statusFilter" id="statusFilter">
                <option value="">All statuses</option>
                @foreach (PurchaseOrderStatus::cases() as $option)
                    <option value="{{ $option->value }}">{{ $option->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
        <x-ui.field label="Vendor" name="vendorFilter" for="vendorFilter" class="sm:max-w-[14rem]">
            <x-ui.select wire:model.live="vendorFilter" id="vendorFilter">
                <option value="">All vendors</option>
                @foreach ($vendors as $vendor)
                    <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>
    </div>

    @if ($orders->isEmpty())
        <x-ui.empty-state title="No purchase orders" description="Orders you place with vendors show up here, with what's still to come." />
    @else
        <x-ui.table :headers="['PO', 'Vendor', 'Ordered', 'Expected', ...($showBranch ? ['Branch'] : []), 'Still to come', 'Value to come', 'Status']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="po-{{ $order->id }}" @class(['opacity-50' => $order->isCancelled()])>
                    <x-ui.table-cell class="font-medium"><a href="{{ route('purchase-orders.show', $order->id) }}" wire:navigate class="text-brand-700 hover:text-brand-800">{{ $order->number() }}</a></x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->vendor?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->order_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->expected_date?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    @if ($showBranch)
                        <x-ui.table-cell>{{ $order->branch?->name ?? '—' }}</x-ui.table-cell>
                    @endif
                    @php($expecting = $order->canReceive())
                    <x-ui.table-cell>{{ $expecting ? $order->outstandingSummary() : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $expecting ? 'Rs '.number_format($order->outstandingAmount(), 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell><x-ui.badge :variant="$order->status->badge()">{{ $order->status->label() }}</x-ui.badge></x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
