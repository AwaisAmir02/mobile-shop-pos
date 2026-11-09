<?php

use App\Enums\WhatsAppOrderStatus;
use App\Models\WhatsAppOrder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('WhatsApp Orders')] class extends Component
{
    use WithPagination;

    #[Url]
    public string $tab = 'pending';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->whatsapp_ordering_enabled, 403);

        if (! in_array($this->tab, ['pending', 'accepted', 'rejected'], true)) {
            $this->tab = 'pending';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['pending', 'accepted', 'rejected'], true) ? $tab : 'pending';
        $this->resetPage();
    }

    public function with(): array
    {
        $statusForTab = [
            'pending' => WhatsAppOrderStatus::Pending,
            'accepted' => WhatsAppOrderStatus::Accepted,
            'rejected' => WhatsAppOrderStatus::Rejected,
        ][$this->tab];

        return [
            'orders' => WhatsAppOrder::query()
                ->with(['contact.customer', 'customer'])
                ->withCount('items')
                ->where('status', $statusForTab->value)
                ->orderByDesc($statusForTab === WhatsAppOrderStatus::Pending ? 'submitted_at' : 'decided_at')
                ->paginate(20),
            'pendingCount' => WhatsAppOrder::where('status', WhatsAppOrderStatus::Pending->value)->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>WhatsApp Orders</x-ui.page-header>
    </x-slot>

    <x-billing.limit-notice limit="whatsapp_orders_per_month" />

    {{-- A view of this page, not another page, so a segmented switch (docs/design-system.md). --}}
    <div class="mb-5 flex max-w-full gap-1 overflow-x-auto rounded-full border border-line bg-surface-sunken/60 p-1 sm:inline-flex" role="group" aria-label="Order status">
        @foreach (['pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $key => $label)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                aria-pressed="{{ $tab === $key ? 'true' : 'false' }}"
                @class([
                    'min-w-0 flex-1 whitespace-nowrap rounded-full px-4 py-1.5 text-sm font-semibold transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-link sm:flex-none',
                    'bg-surface text-ink shadow-soft ring-1 ring-line' => $tab === $key,
                    'text-ink-faint hover:text-ink' => $tab !== $key,
                ])
            >
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
            description="Orders customers place through your WhatsApp chatbot land here first. Nothing is added to Sales until you accept it."
        />
    @else
        @php $whenLabel = $tab === 'pending' ? 'Received' : 'Decided'; @endphp
        <x-ui.table stack :headers="['Order', 'Customer', 'Items', 'Quoted Total', $whenLabel, 'Status', '']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="wa-order-{{ $order->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('whatsapp-orders.show', $order) }}" wire:navigate class="hover:text-link hover:underline">{{ $order->orderNumber() }}</a>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Customer">
                        <span class="font-medium text-ink">{{ $order->customer?->name ?? $order->contact?->displayName() }}</span>
                        <span class="ui-sub">+{{ $order->contact?->wa_id }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Items">{{ $order->items_count }}</x-ui.table-cell>
                    <x-ui.table-cell label="Quoted Total" class="font-semibold text-ink">Rs {{ number_format($order->quoted_total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell :label="$whenLabel">{{ ($tab === 'pending' ? $order->submitted_at : $order->decided_at)?->format('d M Y, h:i A') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="match ($order->status) { WhatsAppOrderStatus::Pending => 'warning', WhatsAppOrderStatus::Accepted => 'success', default => 'danger' }">
                            {{ $order->status->label() }}
                        </x-ui.badge>
                        @if ($order->notify_error)
                            <x-ui.badge variant="danger" title="{{ $order->notify_error }}">Not notified</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.button href="{{ route('whatsapp-orders.show', $order) }}" wire:navigate size="sm" variant="{{ $order->isDecidable() ? 'primary' : 'secondary' }}">
                            {{ $order->isDecidable() ? 'Review' : 'View' }}
                        </x-ui.button>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
