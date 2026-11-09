<?php

use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * D13: Help & Support. Every shop user can reach it from the sidebar, with no
 * screen permission. The owner sees all of the shop's tickets; staff see the
 * ones they opened themselves.
 */
new #[Layout('layouts.app')] #[Title('Help & Support')] class extends Component
{
    use WithPagination;

    /** The screen the user came from, passed on to "Report a problem". */
    #[Url]
    public string $from = '';

    #[Url(as: 'screen')]
    public string $screenTitle = '';

    public function with(): array
    {
        $user = Auth::user();

        return [
            'tickets' => SupportTicket::query()
                ->visibleTo($user)
                ->with(['user', 'firstMessage'])
                ->withCount('messages')
                ->latest('last_message_at')
                ->latest('id')
                ->paginate(15),
            'showOpener' => (bool) $user->is_owner,
            'newTicketUrl' => route('support.create', array_filter(['from' => $this->from, 'screen' => $this->screenTitle])),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Ask us anything about {{ config('app.name') }}. Replies show up here.">
            Help &amp; Support
            <x-slot name="actions">
                <x-ui.button href="{{ $newTicketUrl }}" wire:navigate>Report a problem</x-ui.button>
            </x-slot>
        </x-ui.page-header>
    </x-slot>

    @if ($tickets->isEmpty())
        <x-ui.empty-state title="No tickets yet" description="Something not working, or not sure how to do something? Tell us what happened and add a photo if it helps. We'll reply here.">
            <x-slot name="action">
                <x-ui.button href="{{ $newTicketUrl }}" wire:navigate>Report a problem</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.card :padding="false">
            <x-ui.table stack :headers="array_values(array_filter(['Ticket', 'Screen', $showOpener ? 'Opened by' : null, 'Status', 'Last update']))">
                @foreach ($tickets as $ticket)
                    @php $unread = $ticket->isUnreadForShop(); @endphp
                    <x-ui.table-row wire:key="ticket-{{ $ticket->id }}" data-ticket-row="{{ $ticket->id }}">
                        <x-ui.table-cell primary class="whitespace-normal">
                            <a href="{{ route('support.show', $ticket) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $ticket->number() }}</a>
                            @if ($unread)
                                <x-ui.badge variant="info" data-new-reply>New reply</x-ui.badge>
                            @endif
                            <p class="ui-sub max-w-md">{{ $ticket->preview() }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Screen" class="whitespace-normal">{{ $ticket->screenLabel() ?? '—' }}</x-ui.table-cell>
                        @if ($showOpener)
                            <x-ui.table-cell label="Opened by">{{ $ticket->user?->name ?? 'Removed user' }}</x-ui.table-cell>
                        @endif
                        <x-ui.table-cell label="Status"><x-ui.badge :variant="$ticket->status->badge()">{{ $ticket->status->label() }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell label="Last update">
                            {{ $ticket->last_message_at?->diffForHumans() }}
                            <p class="ui-sub">{{ $ticket->messages_count }} {{ Str::plural('message', $ticket->messages_count) }}</p>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        </x-ui.card>

        <div class="mt-4">{{ $tickets->links() }}</div>
    @endif
</div>
