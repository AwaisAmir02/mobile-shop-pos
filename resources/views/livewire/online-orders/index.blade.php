<?php

use App\Enums\StoreOrderStatus;
use App\Models\StoreOrder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Online Store Orders')] class extends Component
{
    use WithPagination;

    protected const TABS = ['pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    #[Url]
    public string $tab = 'pending';

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('online-orders'), 403);
    }

    public function mount(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'pending';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = array_key_exists($tab, self::TABS) ? $tab : 'pending';
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'tabs' => self::TABS,
            'orders' => StoreOrder::query()
                ->with(['items', 'connection'])
                ->where('status', $this->tab)
                ->orderByDesc($this->tab === 'pending' ? 'placed_at' : 'decided_at')
                ->paginate(20),
            'pendingCount' => StoreOrder::where('status', StoreOrderStatus::Pending->value)->count(),
            'flaggedCount' => StoreOrder::whereNotNull('cancel_flagged_at')->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Online Store Orders</h1>
    </x-slot>

    <x-billing.limit-notice limit="online_orders_per_month" />

    @if ($flaggedCount > 0)
        <x-ui.alert variant="warning" class="mb-4">{{ $flaggedCount }} accepted {{ Str::plural('order', $flaggedCount) }} the store has asked to cancel. See the Accepted tab.</x-ui.alert>
    @endif

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class([
                'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
                'border-brand-600 text-brand-700' => $tab === $key,
                'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
            ])>
                {{ $label }}
                @if ($key === 'pending' && $pendingCount > 0)
                    <x-ui.badge variant="warning" class="ml-1">{{ $pendingCount }}</x-ui.badge>
                @endif
            </button>
        @endforeach
    </div>

    @if ($orders->isEmpty())
        <x-ui.empty-state
            :title="$tab === 'pending' ? 'No orders waiting for review' : 'Nothing here yet'"
            description="Orders from your online store land here first. Nothing is added to Sales until you accept it."
        />
    @else
        <x-ui.table :headers="['Order', 'Store', 'Customer', 'Payment', 'Goods Total', $tab === 'pending' ? 'Placed' : 'Decided', 'Status', '']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="online-order-{{ $order->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        {{ $order->orderNumber() }}
                        <div class="text-xs font-normal text-slate-500">#{{ $order->external_order_id }}</div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->connection?->name }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="font-medium text-slate-900">{{ $order->customer_name ?? '—' }}</div>
                        <div class="text-xs text-slate-500">{{ $order->customer_phone }}</div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->isPrepaid() ? 'Prepaid' : 'Cash on delivery' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($order->goodsTotal(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ ($tab === 'pending' ? $order->placed_at : $order->decided_at)?->format('d M Y, h:i A') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <x-ui.badge :variant="match ($order->status) { StoreOrderStatus::Pending => 'warning', StoreOrderStatus::Accepted => 'success', default => 'danger' }">{{ $order->status->label() }}</x-ui.badge>
                        @if ($order->cancel_flagged_at)
                            <x-ui.badge variant="danger">Cancel requested</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <a href="{{ route('online-orders.show', $order) }}" wire:navigate>
                            <x-ui.button size="sm" variant="{{ $order->isDecidable() ? 'primary' : 'secondary' }}">{{ $order->isDecidable() ? 'Review' : 'View' }}</x-ui.button>
                        </a>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
