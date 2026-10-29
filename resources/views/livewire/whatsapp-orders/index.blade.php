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
        abort_unless(Auth::user()->shop?->whatsapp_enabled, 403);

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
        <h1 class="text-xl font-semibold text-slate-900">WhatsApp Orders</h1>
    </x-slot>

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200">
        @foreach (['pending' => 'Pending', 'accepted' => 'Accepted', 'rejected' => 'Rejected'] as $key => $label)
            <button
                type="button"
                wire:click="setTab('{{ $key }}')"
                @class([
                    'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
                    'border-brand-600 text-brand-700' => $tab === $key,
                    'border-transparent text-slate-500 hover:text-slate-700' => $tab !== $key,
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
        <x-ui.table :headers="['Order', 'Customer', 'Items', 'Quoted Total', $tab === 'pending' ? 'Received' : 'Decided', 'Status', '']">
            @foreach ($orders as $order)
                <x-ui.table-row wire:key="wa-order-{{ $order->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $order->orderNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="font-medium text-slate-900">{{ $order->customer?->name ?? $order->contact?->displayName() }}</div>
                        <div class="text-xs text-slate-500">+{{ $order->contact?->wa_id }}</div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $order->items_count }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($order->quoted_total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ ($tab === 'pending' ? $order->submitted_at : $order->decided_at)?->format('d M Y, h:i A') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <x-ui.badge :variant="match ($order->status) { WhatsAppOrderStatus::Pending => 'warning', WhatsAppOrderStatus::Accepted => 'success', default => 'danger' }">
                            {{ $order->status->label() }}
                        </x-ui.badge>
                        @if ($order->notify_error)
                            <x-ui.badge variant="danger" title="{{ $order->notify_error }}">Not notified</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <a href="{{ route('whatsapp-orders.show', $order) }}" wire:navigate>
                            <x-ui.button size="sm" variant="{{ $order->isDecidable() ? 'primary' : 'secondary' }}">
                                {{ $order->isDecidable() ? 'Review' : 'View' }}
                            </x-ui.button>
                        </a>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $orders->links() }}</div>
    @endif
</div>
