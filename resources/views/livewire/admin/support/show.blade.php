<?php

use App\Actions\Support\ReplyToSupportTicket;
use App\Enums\SupportTicketStatus;
use App\Livewire\Concerns\ComposesSupportMessage;
use App\Livewire\Concerns\Toasts;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] class extends Component
{
    use ComposesSupportMessage, Toasts, WithFileUploads;

    public SupportTicket $ticket;

    public string $status = '';

    public function mount(SupportTicket $ticket): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);

        $this->ticket = $ticket;
        $this->status = $ticket->status->value;
        $this->markRead();
    }

    public function rendering($view): void
    {
        $view->title('Ticket '.$this->ticket->number());
    }

    public function poll(): void
    {
        $this->ticket->refresh();
        $this->status = $this->ticket->status->value;
        $this->markRead();
    }

    public function updatedStatus(string $value): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);

        $this->validate(['status' => ['required', Rule::enum(SupportTicketStatus::class)]]);

        $status = SupportTicketStatus::from($value);

        if ($status === $this->ticket->status) {
            return;
        }

        $this->ticket->update([
            'status' => $status,
            'resolved_at' => $status === SupportTicketStatus::Resolved ? now() : null,
        ]);

        $this->toastSuccess('Ticket '.$this->ticket->number().' marked '.$status->label().'.');
    }

    public function send(ReplyToSupportTicket $reply): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);

        $this->validateComposer(bodyRequired: false);

        $reply->handle($this->ticket, Auth::user(), $this->body, $this->images);

        $this->resetComposer();
        $this->ticket->refresh();
        $this->toastSuccess('Reply sent. The shop sees it next time they open a page.');
    }

    protected function markRead(): void
    {
        if ($this->ticket->admin_read_at === null || $this->ticket->needsAdminAttention()) {
            $this->ticket->forceFill(['admin_read_at' => now()])->save();
        }
    }

    public function with(): array
    {
        $this->ticket->loadMissing(['shop.plan', 'user', 'branch']);

        return [
            'messages' => $this->ticket->messages()->with(['user', 'attachments'])->get(),
            'shopTicketCount' => SupportTicket::where('shop_id', $this->ticket->shop_id)->count(),
        ];
    }
}; ?>

<div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <x-slot name="header">
        <x-ui.page-header back="{{ route('admin.support') }}" back-label="Support" :subtitle="$ticket->shop?->name">
            Ticket {{ $ticket->number() }}
        </x-ui.page-header>
    </x-slot>

    <div class="min-w-0 space-y-5">
        <x-ui.card>
            <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <span class="text-sm font-semibold text-ink">Status</span>
                <x-ui.segmented name="ticket-status" wire:model.live="status" :options="App\Enums\SupportTicketStatus::options()" label="Ticket status" class="w-full max-w-sm sm:w-auto sm:min-w-[22rem]" />
                <p class="text-xs text-ink-faint">
                    @if ($ticket->resolved_at)
                        Resolved {{ $ticket->resolved_at->diffForHumans() }}.
                    @endif
                    If the shop writes again on a resolved ticket, it opens again by itself.
                </p>
            </div>
        </x-ui.card>

        <x-ui.card>
            <div wire:poll.{{ config('support.poll_seconds') }}s.visible="poll">
                <x-support.thread :messages="$messages" admin />
            </div>
        </x-ui.card>

        <x-ui.card title="Reply">
            <x-support.composer
                submit="send"
                :images="$images"
                label="Reply to the shop"
                placeholder="Write a reply…"
                button="Send reply"
                rows="4"
            />
        </x-ui.card>
    </div>

    <div class="space-y-5">
        <x-ui.card title="Details">
            <dl class="space-y-3 text-sm">
                <div>
                    <dt class="text-xs font-semibold text-ink-faint">Shop</dt>
                    <dd><a href="{{ route('admin.shops.show', $ticket->shop) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $ticket->shop?->name }}</a></dd>
                    <dd class="text-ink-faint">{{ $ticket->shop?->plan?->name ?? 'No plan' }} · <a href="{{ route('admin.support', ['shop' => $ticket->shop_id, 'status' => 'all']) }}" wire:navigate class="text-link hover:underline">{{ $shopTicketCount }} {{ Str::plural('ticket', $shopTicketCount) }}</a></dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold text-ink-faint">Opened by</dt>
                    <dd class="text-ink">{{ $ticket->user?->name ?? 'Removed user' }}{{ $ticket->user?->is_owner ? ' (owner)' : '' }}</dd>
                    @if ($ticket->user)
                        <dd class="break-all text-ink-faint">{{ $ticket->user->loginLabel() }}</dd>
                    @endif
                </div>
                <div>
                    <dt class="text-xs font-semibold text-ink-faint">Screen</dt>
                    <dd class="text-ink" data-ticket-screen>{{ $ticket->screenLabel() ?? 'Not linked to a screen' }}</dd>
                    @if ($ticket->screen_url)
                        <dd class="break-all font-mono text-xs text-ink-faint">{{ $ticket->screen_url }}</dd>
                    @endif
                </div>
                @if ($ticket->branch)
                    <div>
                        <dt class="text-xs font-semibold text-ink-faint">Branch</dt>
                        <dd class="text-ink">{{ $ticket->branch->name }}</dd>
                    </div>
                @endif
                @if ($ticket->device)
                    <div>
                        <dt class="text-xs font-semibold text-ink-faint">Device</dt>
                        <dd class="text-ink">{{ $ticket->device }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs font-semibold text-ink-faint">Opened</dt>
                    <dd class="text-ink">{{ $ticket->created_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</dd>
                </div>
            </dl>
        </x-ui.card>
    </div>
</div>
