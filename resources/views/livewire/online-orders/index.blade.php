<?php

use App\Enums\StoreOrderStatus;
use App\Models\StoreConnection;
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
            'stoppedCount' => StoreConnection::notRemoved()->whereNotNull('delivery_paused_at')->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Online Store Orders</x-ui.page-header>
    </x-slot>

    <x-billing.limit-notice limit="online_orders_per_month" />

    @if ($stoppedCount > 0)
        <x-ui.alert variant="danger" class="mb-4">Updates to your online store have stopped because it couldn't be reached, so its stock may be out of date. See Settings, Integrations, Online Store.</x-ui.alert>
    @endif

    @if ($flaggedCount > 0)
        <x-ui.alert variant="warning" class="mb-4">{{ $flaggedCount }} accepted {{ Str::plural('order', $flaggedCount) }} the store has asked to cancel. See the Accepted tab.</x-ui.alert>
    @endif

    {{-- A view of this page, not another page, so a segmented switch (docs/design-system.md). --}}
    <div class="mb-5 flex max-w-full gap-1 overflow-x-auto rounded-full border border-line bg-surface-sunken/60 p-1 sm:inline-flex" role="group" aria-label="Order status">
        @foreach ($tabs as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" aria-pressed="{{ $tab === $key ? 'true' : 'false' }}" @class([
                    'min-w-0 flex-1 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link sm:flex-none',
                    'bg-surface text-ink shadow-soft ring-1 ring-line' => $tab === $key,
                    'text-ink-faint hover:text-ink' => $tab !== $key,
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
        @php $whenLabel = $tab === 'pending' ? 'Placed' : 'Decided'; @endphp
        <x-ui.table stack :headers="['Order', 'Store', 'Customer', 'Payment', 'Goods Total', $whenLabel, 'Status', '']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="online-order-{{ $order->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('online-orders.show', $order) }}" wire:navigate class="hover:text-link hover:underline">{{ $order->orderNumber() }}</a>
                        <span class="ui-sub">#{{ $order->external_order_id }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Store">{{ $order->connection?->name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Customer">
                        <span class="font-medium text-ink">{{ $order->customer_name ?? '—' }}</span>
                        <span class="ui-sub">{{ $order->customer_phone }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Payment">{{ $order->isPrepaid() ? 'Prepaid' : 'Cash on delivery' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Goods Total" class="font-semibold text-ink">Rs {{ number_format($order->goodsTotal(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell :label="$whenLabel">{{ ($tab === 'pending' ? $order->placed_at : $order->decided_at)?->format('d M Y, h:i A') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="match ($order->status) { StoreOrderStatus::Pending => 'warning', StoreOrderStatus::Accepted => 'success', default => 'danger' }">{{ $order->status->label() }}</x-ui.badge>
                        @if ($order->cancel_flagged_at)
                            <x-ui.badge variant="danger">Cancel requested</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.button href="{{ route('online-orders.show', $order) }}" wire:navigate size="sm" variant="{{ $order->isDecidable() ? 'primary' : 'secondary' }}">{{ $order->isDecidable() ? 'Review' : 'View' }}</x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
