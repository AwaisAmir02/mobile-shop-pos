<?php

use App\Enums\AdCampaignStatus;
use App\Jobs\BuildMetaAdCampaign;
use App\Livewire\Concerns\Toasts;
use App\Models\MetaAdCampaign;
use App\Services\Marketing\MarketingStats;
use App\Services\Marketing\MetaAdsManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Ad')] class extends Component
{
    use Toasts;

    public MetaAdCampaign $campaign;

    public bool $confirmSpend = false;

    public function mount(MetaAdCampaign $campaign): void
    {
        abort_unless(Auth::user()->shop?->ads_enabled, 403);
        $this->campaign = $campaign;
    }

    protected function authorizeAds(): void
    {
        abort_unless(Auth::user()->shop?->ads_enabled && Auth::user()->hasAccessTo('marketing-ads'), 403);
    }

    public function retrySetup(): void
    {
        $this->authorizeAds();
        abort_unless($this->campaign->status === AdCampaignStatus::Failed, 422);
        BuildMetaAdCampaign::dispatch($this->campaign->id);
        $this->campaign->refresh();
        $this->toastSuccess('Retrying setup on Meta.');
    }

    public function launch(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        $this->validate(['confirmSpend' => ['accepted']], ['confirmSpend.accepted' => 'Please confirm the maximum spend first.']);

        $this->campaign = $ads->launch($this->campaign, Auth::id(), $this->campaign->maxSpend());
        $this->confirmSpend = false;
        $this->toastSuccess('Your ad is submitted. Meta reviews it before it starts showing (usually within a day).');
    }

    public function pause(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        $this->campaign = $ads->setRunning($this->campaign, false);
        $this->toastSuccess('Ad paused.');
    }

    public function resume(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        $this->campaign = $ads->setRunning($this->campaign, true);
        $this->toastSuccess('Ad resumed.');
    }

    public function stop(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        $this->campaign = $ads->end($this->campaign);
        $this->toastSuccess('Ad stopped for good.');
    }

    public function discard(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        $ads->discard($this->campaign);
        $this->toastSuccess('Ad discarded.');
        $this->redirectRoute('marketing.ads', navigate: true);
    }

    public function refreshResults(MetaAdsManager $ads): void
    {
        $this->authorizeAds();

        try {
            $this->campaign = $ads->sync($this->campaign);
        } catch (\RuntimeException $e) {
            $this->toastError('Could not refresh: '.$e->getMessage());

            return;
        }

        $this->toastSuccess('Results refreshed.');
    }

    public function with(): array
    {
        $this->campaign->refresh();

        return [
            'results' => MarketingStats::adResults($this->campaign),
            'steps' => [
                'Campaign' => (bool) $this->campaign->meta_campaign_id,
                'Audience & budget' => (bool) $this->campaign->meta_adset_id,
                'Ad design' => (bool) $this->campaign->meta_creative_id,
                'Ad' => (bool) $this->campaign->meta_ad_id,
            ],
        ];
    }
}; ?>

<div @if ($campaign->status === AdCampaignStatus::Creating) wire:poll.4s @endif>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.ads" />

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <h2 class="text-lg font-semibold text-slate-900">{{ $campaign->post?->title ?? $campaign->name }}</h2>
        <x-ui.badge :variant="$campaign->status->badge()">{{ $campaign->status->label() }}</x-ui.badge>
        @if ($campaign->effective_status && $campaign->status->isLive())
            <x-ui.badge>Meta: {{ str_replace('_', ' ', strtolower($campaign->effective_status)) }}</x-ui.badge>
        @endif
    </div>

    <x-ui.error :messages="$errors->get('launch')" class="mb-3" />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            @if ($campaign->status->isLive() || in_array($campaign->status, [AdCampaignStatus::Completed, AdCampaignStatus::Ended], true))
                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach ([
                        'Spent' => $campaign->currency.' '.number_format($results['spend'], 2),
                        'People reached' => number_format($campaign->metric('reach')),
                        $campaign->goal === \App\Enums\AdGoal::WhatsAppMessages ? 'WhatsApp chats started' : 'Post engagement' => number_format($campaign->goal === \App\Enums\AdGoal::WhatsAppMessages ? $campaign->metric('conversations') : $campaign->metric('engagement')),
                        'WhatsApp orders' => $results['orders'],
                        'Sales from this ad' => 'Rs '.number_format($results['revenue']),
                        'Return on ad spend' => $results['roas'] !== null ? $results['roas'].'×' : '—',
                    ] as $label => $value)
                        <x-ui.card :padding="false">
                            <div class="px-5 py-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
                                <p class="mt-1 text-xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>
                            </div>
                        </x-ui.card>
                    @endforeach
                </div>
                <div class="flex items-center gap-3 text-xs text-slate-500">
                    <span>Results updated {{ $campaign->metrics_synced_at?->diffForHumans() ?? 'never' }}.</span>
                    <button type="button" wire:click="refreshResults" class="font-medium text-brand-700 hover:text-brand-800">Refresh now</button>
                </div>
            @endif

            @if ($campaign->review_feedback)
                <div class="rounded-lg bg-red-50 p-4 text-sm text-red-800">
                    <p class="font-medium">Meta flagged this ad</p>
                    <p class="mt-1">{{ $campaign->review_feedback }}</p>
                </div>
            @endif

            @if (in_array($campaign->status, [AdCampaignStatus::Draft, AdCampaignStatus::Creating, AdCampaignStatus::Failed, AdCampaignStatus::Ready], true))
                <x-ui.card title="Setup on Meta" description="Everything is created paused — nothing is spent until you launch.">
                    <ol class="space-y-2 text-sm">
                        @foreach ($steps as $label => $done)
                            <li class="flex items-center gap-2">
                                <span @class(['flex h-5 w-5 items-center justify-center rounded-full text-xs', 'bg-emerald-100 text-emerald-700' => $done, 'bg-slate-100 text-slate-400' => ! $done])>{{ $done ? '✓' : '·' }}</span>
                                {{ $label }}
                            </li>
                        @endforeach
                    </ol>
                    @if ($campaign->status === AdCampaignStatus::Failed)
                        <p class="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ $campaign->error }}</p>
                        <div class="mt-3 flex gap-2">
                            <x-ui.button size="sm" wire:click="retrySetup">Retry setup</x-ui.button>
                            <x-ui.button size="sm" variant="ghost" wire:click="discard" wire:confirm="Discard this ad?">Discard</x-ui.button>
                        </div>
                    @endif
                </x-ui.card>
            @endif

            @if ($campaign->status === AdCampaignStatus::Ready)
                <x-ui.card title="Launch">
                    <form wire:submit="launch" class="space-y-4">
                        <label class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                            <input type="checkbox" wire:model="confirmSpend" class="mt-0.5 rounded border-amber-300 text-brand-600 focus:ring-brand-500">
                            <span>I understand Meta will charge my ad account up to <strong>{{ $campaign->currency }} {{ number_format($campaign->maxSpend(), 2) }}</strong> ({{ $campaign->currency }} {{ number_format($campaign->daily_budget) }} a day for {{ $campaign->days() }} {{ \Illuminate\Support\Str::plural('day', $campaign->days()) }}). HisabOKitab does not charge for ad spend.</span>
                        </label>
                        <x-ui.error :messages="$errors->get('confirmSpend')" />
                        <div class="flex gap-2">
                            <x-ui.button type="submit" wire:loading.attr="disabled">Launch ad</x-ui.button>
                            <x-ui.button variant="ghost" wire:click="discard" wire:confirm="Discard this ad? It will be removed from Meta.">Discard</x-ui.button>
                        </div>
                    </form>
                </x-ui.card>
            @endif

            @if ($campaign->status->isLive())
                <div class="flex flex-wrap gap-2">
                    @if ($campaign->status === AdCampaignStatus::Active)
                        <x-ui.button variant="secondary" wire:click="pause">Pause</x-ui.button>
                    @else
                        <x-ui.button variant="secondary" wire:click="resume">Resume</x-ui.button>
                    @endif
                    <x-ui.button variant="danger" wire:click="stop" wire:confirm="Stop this ad for good? It cannot be restarted.">Stop</x-ui.button>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <x-ui.card title="Details">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Goal</dt><dd class="text-right text-slate-900">{{ $campaign->goal->label() }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Budget</dt><dd class="text-slate-900">{{ $campaign->currency }} {{ number_format($campaign->daily_budget) }}/day</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Runs</dt><dd class="text-right text-slate-900">{{ $campaign->start_at->format('d M, h:i A') }} – {{ $campaign->end_at->format('d M Y') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Where</dt><dd class="text-right text-slate-900">
                        @switch($campaign->targeting['mode'] ?? 'country')
                            @case('cities') {{ collect($campaign->targeting['cities'] ?? [])->pluck('name')->implode(', ') }} @break
                            @case('radius') {{ $campaign->targeting['radius_km'] }} km around the shop @break
                            @default All of Pakistan
                        @endswitch
                    </dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Who</dt><dd class="text-slate-900">{{ ['all' => 'Everyone', 'male' => 'Men', 'female' => 'Women'][$campaign->targeting['gender'] ?? 'all'] }}, {{ $campaign->targeting['age_min'] ?? 18 }}–{{ $campaign->targeting['age_max'] ?? 65 }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">Shows on</dt><dd class="text-right text-slate-900">Facebook, Instagram{{ ! empty($campaign->targeting['whatsapp_status']) ? ', WhatsApp Status' : '' }}</dd></div>
                    @if ($campaign->launched_at)
                        <div class="flex justify-between gap-3"><dt class="text-slate-500">Launched</dt><dd class="text-slate-900">{{ $campaign->launched_at->format('d M, h:i A') }}</dd></div>
                    @endif
                </dl>
            </x-ui.card>
            @if ($campaign->post)
                <a href="{{ route('marketing.posts.show', $campaign->post) }}" wire:navigate class="block text-sm font-medium text-brand-700 hover:text-brand-800">View the post →</a>
            @endif
        </div>
    </div>
</div>
