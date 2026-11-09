<?php

use App\Actions\Support\ReplyToSupportTicket;
use App\Enums\SupportTicketStatus;
use App\Livewire\Concerns\ComposesSupportMessage;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use ComposesSupportMessage, WithFileUploads;

    public SupportTicket $ticket;

    public bool $justOpened = false;

    public function mount(SupportTicket $ticket): void
    {
        // The shop scope already hides other shops' tickets (404 on binding);
        // this keeps a staff member to their own.
        abort_unless($ticket->isVisibleTo(Auth::user()), 404);

        $this->ticket = $ticket;
        $this->justOpened = (bool) session('support-opened');
        $this->markRead();
    }

    public function rendering($view): void
    {
        $view->title('Ticket '.$this->ticket->number());
    }

    /** Called by the thread's poll, so a reply that arrives while open counts as read. */
    public function poll(): void
    {
        $this->ticket->refresh();
        $this->markRead();
    }

    public function send(ReplyToSupportTicket $reply): void
    {
        $this->validateComposer(bodyRequired: false);

        try {
            $reply->handle($this->ticket, Auth::user(), $this->body, $this->images);
        } catch (\RuntimeException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        $this->resetComposer();
        $this->justOpened = false;
        $this->ticket->refresh();
    }

    protected function markRead(): void
    {
        if ($this->ticket->isUnreadForShop()) {
            $this->ticket->forceFill(['shop_read_at' => now()])->save();
        }
    }

    public function with(): array
    {
        return [
            'messages' => $this->ticket->messages()->with(['user', 'attachments'])->get(),
            'resolved' => $this->ticket->status === SupportTicketStatus::Resolved,
        ];
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-5">
    <x-slot name="header">
        <x-ui.page-header back="{{ route('support.index') }}" back-label="Help & Support" :subtitle="$ticket->screenLabel() ? 'About: '.$ticket->screenLabel() : 'Opened '.$ticket->created_at->format('d M Y')">
            Ticket {{ $ticket->number() }}
        </x-ui.page-header>
    </x-slot>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-ink-muted">
        <x-ui.badge :variant="$ticket->status->badge()" data-ticket-status>{{ $ticket->status->label() }}</x-ui.badge>
        <span>Opened by {{ $ticket->user?->name ?? 'Removed user' }} · {{ $ticket->created_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</span>
        @if ($ticket->screen_url)
            <a href="{{ $ticket->screen_url }}" wire:navigate class="font-semibold text-link hover:underline">Go to that screen</a>
        @endif
    </div>

    @if ($justOpened)
        <x-ui.note class="!mb-0" data-ticket-sent>Sent. We'll reply on this page, and you'll see a marker on Help &amp; Support when we do.</x-ui.note>
    @endif

    <x-ui.card>
        <div wire:poll.{{ config('support.poll_seconds') }}s.visible="poll">
            <x-support.thread :messages="$messages" />
        </div>
    </x-ui.card>

    <x-ui.card>
        @if ($resolved)
            <p class="mb-3 text-sm text-ink-muted" data-resolved-note>This ticket is marked resolved. Still stuck? Write below and it opens again.</p>
        @endif
        <x-support.composer
            submit="send"
            :images="$images"
            label="Your reply"
            placeholder="Write a reply…"
            :button="$resolved ? 'Reply and reopen' : 'Send reply'"
            rows="3"
        />
    </x-ui.card>
</div>
