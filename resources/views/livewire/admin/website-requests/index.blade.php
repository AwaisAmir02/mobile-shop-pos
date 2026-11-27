<?php

use App\Enums\WebsiteRequestStatus;
use App\Models\WebsiteRequest;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/** Every shop's "build my website" request (O9), newest first. Super admin only. */
new #[Layout('layouts.app')] #[Title('Website Requests')] class extends Component
{
    use WithPagination;

    #[Url(except: 'open')]
    public string $show = 'open';

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        $query = WebsiteRequest::withoutGlobalScopes()->with(['shop', 'user'])->latest('id');

        return [
            'requests' => (match ($this->show) {
                'all' => $query,
                'done' => $query->whereIn('status', [WebsiteRequestStatus::Fulfilled->value, WebsiteRequestStatus::Declined->value]),
                default => $query->whereIn('status', [WebsiteRequestStatus::Submitted->value, WebsiteRequestStatus::InProgress->value]),
            })->paginate(25),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Shops asking us to build their website. The conversation is each request's support ticket.">Website Requests</x-ui.page-header>
    </x-slot>

    <div class="mb-4 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 text-sm font-semibold" role="group" aria-label="Show">
        @foreach (['open' => 'Open', 'done' => 'Done', 'all' => 'All'] as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}" @class(['rounded-full px-3 py-1.5', 'bg-surface text-ink shadow-soft ring-1 ring-line' => $show === $value, 'text-ink-faint hover:text-ink' => $show !== $value])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($requests->isEmpty())
        <x-ui.empty-state title="No website requests here" />
    @else
        <x-ui.table stack :headers="['Request', 'Shop', 'Wants', 'Status']">
            @foreach ($requests as $request)
                <x-ui.table-row wire:key="wr-{{ $request->id }}">
                    <x-ui.table-cell primary>
                        <a href="{{ route('admin.website-requests.show', $request->id) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $request->number() }}</a>
                        <span class="block text-xs text-ink-faint">{{ $request->created_at->format('d M Y') }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Shop">{{ $request->shop?->name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Wants">{{ \App\Actions\WebsiteRequests::GOALS[$request->requirements['goal'] ?? ''] ?? '' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status"><x-ui.badge :variant="$request->status->badge()">{{ $request->status->label() }}</x-ui.badge></x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $requests->links() }}</div>
    @endif
</div>
