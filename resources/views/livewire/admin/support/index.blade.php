<?php

use App\Enums\SupportTicketStatus;
use App\Models\Shop;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Support')] class extends Component
{
    use WithPagination;

    /** 'active' = Open and In Progress. */
    #[Url]
    public string $status = 'active';

    #[Url]
    public ?int $shop = null;

    #[Url(as: 'q')]
    public string $search = '';

    public function updating($name): void
    {
        if (in_array($name, ['status', 'shop', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function clearShop(): void
    {
        $this->shop = null;
        $this->resetPage();
    }

    public function with(): array
    {
        abort_unless(Auth::user()?->is_super_admin, 403);

        $search = trim($this->search);

        return [
            'tickets' => SupportTicket::query()
                ->with(['shop', 'user', 'firstMessage'])
                ->withCount('messages')
                ->when($this->status === 'active', fn ($q) => $q->where('status', '!=', SupportTicketStatus::Resolved->value))
                ->when(in_array($this->status, array_column(SupportTicketStatus::cases(), 'value'), true), fn ($q) => $q->where('status', $this->status))
                ->when($this->shop, fn ($q) => $q->where('shop_id', $this->shop))
                ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                    ->when(ctype_digit(ltrim($search, '#')), fn ($q) => $q->orWhere('id', (int) ltrim($search, '#')))
                    ->orWhereHas('shop', fn ($q) => $q->where('name', 'like', '%'.$search.'%'))
                    ->orWhereHas('messages', fn ($q) => $q->where('body', 'like', '%'.$search.'%'))))
                // Tickets waiting on us first, then the most recently active.
                ->orderByRaw("CASE WHEN status != ? AND (admin_read_at IS NULL OR last_shop_message_at > admin_read_at) THEN 0 ELSE 1 END", [SupportTicketStatus::Resolved->value])
                ->latest('last_message_at')
                ->latest('id')
                ->paginate(20),
            'shopName' => $this->shop ? Shop::whereKey($this->shop)->value('name') : null,
            'waiting' => SupportTicket::waitingOnAdmin()->count(),
            'statusOptions' => ['active' => 'Open & In Progress'] + SupportTicketStatus::options() + ['all' => 'All'],
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Questions and problems sent from shops.">Support</x-ui.page-header>
    </x-slot>

    <x-ui.card :padding="false">
        <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 sm:px-5">
            <x-ui.select wire:model.live="status" class="w-48" aria-label="Filter by status">
                @foreach ($statusOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-ui.select>
            <x-ui.input type="search" wire:model.live.debounce.400ms="search" placeholder="Search shop, message or #number" class="max-w-xs" aria-label="Search tickets" />
            @if ($shopName)
                <span class="inline-flex items-center gap-1.5 rounded-full bg-primary-soft px-3 py-1 text-sm font-semibold text-on-primary-soft">
                    {{ $shopName }}
                    <button type="button" wire:click="clearShop" class="text-on-primary-soft/70 hover:text-on-primary-soft" aria-label="Show all shops">&times;</button>
                </span>
            @endif
            <span class="ml-auto text-sm text-ink-faint" data-waiting-count>{{ $waiting }} waiting on a reply</span>
        </div>

        @if ($tickets->isEmpty())
            <div class="p-4 sm:p-5">
                <x-ui.empty-state title="No tickets here" description="Tickets appear when a shop sends one from Help & Support." />
            </div>
        @else
            <x-ui.table stack :headers="['Ticket', 'Shop', 'Screen', 'Status', 'Last update']">
                @foreach ($tickets as $ticket)
                    <x-ui.table-row wire:key="admin-ticket-{{ $ticket->id }}" data-ticket-row="{{ $ticket->id }}">
                        <x-ui.table-cell primary class="whitespace-normal">
                            <a href="{{ route('admin.support.show', $ticket) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $ticket->number() }}</a>
                            @if ($ticket->needsAdminAttention())
                                <x-ui.badge variant="warning" data-needs-reply>Needs reply</x-ui.badge>
                            @endif
                            <p class="ui-sub max-w-md">{{ $ticket->preview() }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Shop" class="whitespace-normal">
                            <span class="font-medium text-ink">{{ $ticket->shop?->name }}</span>
                            <p class="ui-sub">{{ $ticket->user?->name ?? 'Removed user' }}{{ $ticket->user?->is_owner ? ' · owner' : '' }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Screen" class="whitespace-normal">{{ $ticket->screenLabel() ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Status"><x-ui.badge :variant="$ticket->status->badge()">{{ $ticket->status->label() }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell label="Last update">
                            {{ $ticket->last_message_at?->diffForHumans() }}
                            <p class="ui-sub">{{ $ticket->messages_count }} {{ Str::plural('message', $ticket->messages_count) }}</p>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $tickets->links() }}</div>
</div>
