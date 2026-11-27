<?php

use App\Actions\WebsiteRequests;
use App\Enums\StoreDriver;
use App\Enums\WebsiteRequestStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\StoreConnection;
use App\Models\WebsiteRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * One website request (O9): the requirements, the conversation (its support
 * ticket), moving it along, and marking it delivered with the site's address
 * and the shop's own-website store connection it was given. Super admin only.
 */
new #[Layout('layouts.app')] #[Title('Website Request')] class extends Component
{
    use Toasts;

    #[Locked]
    public int $requestId;

    public string $note = '';
    public string $siteUrl = '';
    public string $storeConnectionId = '';

    public function mount(int $websiteRequest): void
    {
        $this->requestId = WebsiteRequest::withoutGlobalScopes()->findOrFail($websiteRequest)->id;
    }

    protected function websiteRequest(): WebsiteRequest
    {
        return WebsiteRequest::withoutGlobalScopes()->with(['shop', 'ticket', 'connection'])->findOrFail($this->requestId);
    }

    public function setStatus(string $status): void
    {
        WebsiteRequests::setStatus($this->websiteRequest(), WebsiteRequestStatus::from($status), Auth::user(), $this->note);
        $this->note = '';
        $this->toastSuccess('Updated.');
    }

    public function fulfil(): void
    {
        $this->validate(['siteUrl' => ['required', 'url', 'max:255'], 'storeConnectionId' => ['nullable', 'integer']], [], ['siteUrl' => 'site address']);
        WebsiteRequests::fulfil($this->websiteRequest(), $this->storeConnectionId !== '' ? (int) $this->storeConnectionId : null, $this->siteUrl, $this->note, Auth::user());
        $this->toastSuccess('Marked delivered. The shop was told in the conversation.');
    }

    public function with(): array
    {
        $request = $this->websiteRequest();

        return [
            'request' => $request,
            'connections' => StoreConnection::withoutGlobalScopes()->where('shop_id', $request->shop_id)->where('driver', StoreDriver::Api->value)->whereNull('removed_at')->orderBy('id')->get(),
            'goals' => WebsiteRequests::GOALS,
        ];
    }
}; ?>

<div class="mx-auto max-w-3xl space-y-6">
    <x-slot name="header">
        <x-ui.page-header back="{{ route('admin.website-requests') }}" back-label="Website Requests" :subtitle="$request->shop?->name">{{ $request->number() }}</x-ui.page-header>
    </x-slot>

    <x-ui.card title="What they asked for">
        <dl class="space-y-2 text-sm" data-requirements>
            <div><dt class="text-ink-faint">About the business</dt><dd class="whitespace-pre-line text-ink">{{ $request->requirements['about'] ?? '' }}</dd></div>
            <div><dt class="text-ink-faint">The site should</dt><dd class="text-ink">{{ $goals[$request->requirements['goal'] ?? ''] ?? '' }}</dd></div>
            <div><dt class="text-ink-faint">Products</dt><dd class="text-ink">{{ $request->requirements['products'] ?? '' }}</dd></div>
            <div><dt class="text-ink-faint">Domain</dt><dd class="text-ink">{{ $request->requirements['domain'] ?? 'Needs one' }}</dd></div>
            @if (filled($request->requirements['references'] ?? null))
                <div><dt class="text-ink-faint">Sites they like</dt><dd class="text-ink">{{ $request->requirements['references'] }}</dd></div>
            @endif
            @if (filled($request->requirements['deadline'] ?? null))
                <div><dt class="text-ink-faint">Wanted by</dt><dd class="text-ink">{{ $request->requirements['deadline'] }}</dd></div>
            @endif
            <div><dt class="text-ink-faint">Phone</dt><dd class="text-ink">{{ $request->requirements['phone'] ?? '' }}</dd></div>
        </dl>
        @if ($request->ticket)
            <p class="mt-4 text-sm"><a href="{{ route('admin.support.show', $request->ticket) }}" wire:navigate class="font-semibold text-link hover:underline">Open the conversation</a> (photos and replies are there)</p>
        @endif
    </x-ui.card>

    <x-ui.card title="Where it stands">
        <div class="space-y-4 text-sm">
            <x-ui.badge :variant="$request->status->badge()">{{ $request->status->label() }}</x-ui.badge>
            @if ($request->site_url)
                <p>Site: <a href="{{ $request->site_url }}" target="_blank" rel="noopener" class="text-link hover:underline">{{ $request->site_url }}</a>{{ $request->connection ? ' · connection "'.$request->connection->name.'"' : '' }} · {{ $request->fulfilled_at?->format('d M Y') }}</p>
            @endif
            <x-ui.field label="Note to the shop" name="note" for="note" help="Optional. Sent in the conversation.">
                <x-ui.input wire:model="note" id="note" maxlength="1000" />
            </x-ui.field>
            @if ($request->status->isOpen())
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" wire:click="setStatus('{{ WebsiteRequestStatus::InProgress->value }}')">Mark in progress</x-ui.button>
                    <x-ui.button size="sm" variant="danger-ghost" wire:click="setStatus('{{ WebsiteRequestStatus::Declined->value }}')">Decline</x-ui.button>
                </div>
                <form wire:submit="fulfil" class="space-y-3 border-t border-line pt-4" data-fulfil-form>
                    <x-ui.field label="Site address" name="siteUrl" for="siteUrl">
                        <x-ui.input wire:model="siteUrl" id="siteUrl" placeholder="https://mybrand.pk" />
                    </x-ui.field>
                    <x-ui.field label="Store connection the site uses" name="storeConnectionId" for="storeConnectionId" help="The shop's own-website connection whose API token and secret the site was set up with.">
                        <x-ui.select wire:model="storeConnectionId" id="storeConnectionId">
                            <option value="">None yet</option>
                            @foreach ($connections as $connection)
                                <option value="{{ $connection->id }}">{{ $connection->name }} ({{ $connection->public_id }})</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.button type="submit">Mark delivered</x-ui.button>
                </form>
            @endif
        </div>
    </x-ui.card>
</div>
