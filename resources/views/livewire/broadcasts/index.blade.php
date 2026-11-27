<?php

use App\Actions\Broadcasts;
use App\Livewire\Concerns\Toasts;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Broadcasts (Services group): one message, a diet plan or new timings, to a
 * list of customers. Sent from the shop's WhatsApp number to anyone who
 * messaged in the last 24 hours; everyone else gets a link to send it by hand
 * (App\Actions\Broadcasts explains why).
 */
new #[Layout('layouts.app')] #[Title('Broadcasts')] class extends Component
{
    use Toasts, WithFileUploads;

    public string $audience = 'active_members';
    public string $body = '';
    public $attachment = null;
    public array $picked = [];

    #[Url(as: 'broadcast', except: null)]
    public ?int $broadcastId = null;

    public function send(): void
    {
        $this->validate(['body' => ['required', 'string', 'max:4000'], 'attachment' => Broadcasts::ATTACHMENT_RULES, 'audience' => ['required', 'in:'.implode(',', array_keys(Broadcasts::AUDIENCES))]]);
        $broadcast = Broadcasts::send(Auth::user()->shop_id, $this->audience, $this->body, $this->attachment, $this->picked, Auth::id());
        $this->reset(['body', 'attachment', 'picked']);
        $this->broadcastId = $broadcast->id;
        $sent = $broadcast->recipients->where('delivery', \App\Enums\BroadcastDelivery::Sent)->count();
        $this->toastSuccess($sent.' sent on WhatsApp, '.($broadcast->recipients->count() - $sent).' to send by hand below.');
    }

    public function openByHand(int $recipientId): void
    {
        $recipient = BroadcastRecipient::findOrFail($recipientId);
        $url = Broadcasts::openByHand($recipient, Broadcast::findOrFail($recipient->broadcast_id));

        if ($url !== null) {
            $this->js('window.open('.json_encode($url).', "_blank")');
        }
    }

    public function attachmentDownload(int $id): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $broadcast = Broadcast::findOrFail($id);
        abort_unless($broadcast->attachment_path !== null, 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->download($broadcast->attachment_path, $broadcast->attachment_name);
    }

    public function with(): array
    {
        return [
            'count' => $this->audience !== 'picked' || $this->picked !== [] ? Broadcasts::recipients(Auth::user()->shop_id, $this->audience, $this->picked)->count() : 0,
            'customers' => $this->audience === 'picked' ? Customer::query()->whereNotNull('phone')->orderBy('name')->get(['id', 'name', 'phone']) : collect(),
            'history' => Broadcast::query()->withCount('recipients')->latest('id')->limit(20)->get(),
            'open' => $this->broadcastId ? Broadcast::with('recipients')->find($this->broadcastId) : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="One message to many customers: a diet plan, new timings, an offer">Broadcasts</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="New message" data-new-broadcast>
            <form wire:submit="send" class="space-y-3">
                <x-ui.field label="To" name="audience" for="audience">
                    <x-ui.select wire:model.live="audience" id="audience">
                        @foreach (\App\Actions\Broadcasts::AUDIENCES as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @if ($audience === 'picked')
                    <div class="max-h-48 space-y-1 overflow-y-auto rounded-xl border border-line p-2">
                        @foreach ($customers as $customer)
                            <x-ui.checkbox wire:model.live="picked" :value="(string) $customer->id" :label="$customer->name.' · '.$customer->phone" />
                        @endforeach
                    </div>
                @endif
                <p class="text-xs text-ink-faint" data-audience-count>{{ $count }} {{ str('person')->plural($count) }}</p>
                <x-ui.field label="Message" name="body" for="broadcastBody"><textarea wire:model="body" id="broadcastBody" rows="6" class="block w-full rounded-lg border-line-strong bg-surface px-3 py-2 text-sm text-ink focus:border-link focus:ring-link"></textarea></x-ui.field>
                <x-ui.field label="File (PDF or picture, optional)" name="attachment" for="broadcastFile"><input type="file" wire:model="attachment" id="broadcastFile" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-ink-muted" /></x-ui.field>
                <p class="text-xs text-ink-faint">Customers who messaged you in the last 24 hours get it from your WhatsApp Business number. For the rest you'll get a button each that opens WhatsApp with the message typed in (attach the file yourself).</p>
                <x-ui.button type="submit">Send</x-ui.button>
            </form>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-2">
            @if ($open)
                <x-ui.card :title="'Sent '.$open->created_at->timezone('Asia/Karachi')->format('d M, g:i A')" :description="\Illuminate\Support\Str::limit($open->body, 120)" :padding="false" data-broadcast-recipients>
                    @if ($open->attachment_path)
                        <x-slot name="actions"><x-ui.button size="sm" variant="secondary" wire:click="attachmentDownload({{ $open->id }})">{{ $open->attachment_name }}</x-ui.button></x-slot>
                    @endif
                    <ul class="divide-y divide-line">
                        @foreach ($open->recipients as $recipient)
                            <li wire:key="recipient-{{ $recipient->id }}" class="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                                <span><span class="font-semibold text-ink">{{ $recipient->name }}</span> <span class="text-xs text-ink-faint">{{ $recipient->phone }}</span></span>
                                @if (in_array($recipient->delivery, [\App\Enums\BroadcastDelivery::ByHand, \App\Enums\BroadcastDelivery::Failed, \App\Enums\BroadcastDelivery::OpenedByHand], true))
                                    <x-ui.button size="sm" :variant="$recipient->delivery === \App\Enums\BroadcastDelivery::OpenedByHand ? 'secondary' : 'primary'" wire:click="openByHand({{ $recipient->id }})" data-send-by-hand="{{ $recipient->id }}">{{ $recipient->delivery === \App\Enums\BroadcastDelivery::OpenedByHand ? 'Open again' : 'Send on WhatsApp' }}</x-ui.button>
                                @else
                                    <x-ui.badge variant="success">Sent</x-ui.badge>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            <x-ui.card title="Earlier" :padding="false">
                @if ($history->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-faint">Nothing sent yet.</p>
                @else
                    <ul class="divide-y divide-line">
                        @foreach ($history as $item)
                            <li wire:key="broadcast-{{ $item->id }}"><button type="button" wire:click="$set('broadcastId', {{ $item->id }})" class="flex w-full justify-between gap-3 px-5 py-2.5 text-left text-sm hover:bg-surface-sunken"><span class="truncate text-ink">{{ \Illuminate\Support\Str::limit($item->body, 70) }}</span><span class="shrink-0 text-xs text-ink-faint">{{ $item->recipients_count }} · {{ $item->created_at->timezone('Asia/Karachi')->format('d M') }}</span></button></li>
                        @endforeach
                    </ul>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
