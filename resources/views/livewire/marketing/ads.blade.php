<?php

use App\Enums\PostKind;
use App\Models\MarketingPost;
use App\Models\MetaAdCampaign;
use App\Services\Marketing\MarketingStats;
use App\Services\Marketing\MetaAdsManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Ads')] class extends Component
{
    use WithPagination;

    public string $postId = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->ads_enabled, 403);
    }

    public function startFromPost(): void
    {
        $this->validate(['postId' => ['required', 'integer']]);
        $post = MarketingPost::findOrFail((int) $this->postId);

        $this->redirectRoute('marketing.ads.create', ['post' => $post->id], navigate: true);
    }

    public function with(): array
    {
        $campaigns = MetaAdCampaign::query()->with('post')->latest('id')->paginate(15);

        return [
            'adAccount' => MetaAdsManager::adAccount(Auth::user()->shop_id),
            'campaigns' => $campaigns,
            'results' => $campaigns->getCollection()->mapWithKeys(fn (MetaAdCampaign $c) => [$c->id => MarketingStats::adResults($c)]),
            'totals' => (new MarketingStats(now()->subDays(29), now()))->adTotals(),
            'posts' => MarketingPost::query()->whereIn('kind', [PostKind::Product->value, PostKind::Review->value])->latest('id')->limit(30)->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.ads" />

    @if (! $adAccount)
        <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
            Connect your Meta ad account in Settings → Integrations to run ads. Meta charges your own ad account — HisabOKitab never charges for ad spend.
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            'Ad spend (30 days)' => ($adAccount?->meta['currency'] ?? 'Rs').' '.number_format($totals['spend'], 2),
            'WhatsApp orders from ads' => $totals['orders'],
            'Sales from ads' => 'Rs '.number_format($totals['revenue']),
            'Return on ad spend' => $totals['roas'] !== null ? $totals['roas'].'×' : '—',
        ] as $label => $value)
            <x-ui.card :padding="false">
                <div class="px-5 py-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-slate-900">{{ $value }}</p>
                </div>
            </x-ui.card>
        @endforeach
    </div>

    @if ($adAccount)
        <x-ui.card title="Start a new ad" description="Ads are made from one of your posts, so the same image, caption and REF code are used." class="mt-6">
            <form wire:submit="startFromPost" class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-ui.field label="Post" name="postId" for="ad-post" class="flex-1">
                    <x-ui.select wire:model="postId" id="ad-post">
                        <option value="">Choose a post…</option>
                        @foreach ($posts as $post)
                            <option value="{{ $post->id }}">{{ $post->title }} · {{ $post->refTag() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.button type="submit">Continue</x-ui.button>
            </form>
        </x-ui.card>
    @endif

    <div class="mt-6">
        @if ($campaigns->isEmpty())
            <x-ui.empty-state title="No ads yet" description="Boost a post to reach more people, or run a WhatsApp ad that brings customers straight into your chat." />
        @else
            <x-ui.table :headers="['Campaign', 'Goal', 'Status', 'Budget', 'Spent', 'Orders', 'Sales', 'ROAS', '']">
                @foreach ($campaigns as $campaign)
                    @php($r = $results[$campaign->id])
                    <x-ui.table-row wire:key="ad-{{ $campaign->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">
                            {{ $campaign->post?->title ?? $campaign->name }}
                            <div class="text-xs text-slate-400">{{ $campaign->start_at->format('d M') }} – {{ $campaign->end_at->format('d M Y') }}</div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $campaign->goal->label() }}</x-ui.table-cell>
                        <x-ui.table-cell><x-ui.badge :variant="$campaign->status->badge()">{{ $campaign->status->label() }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">{{ $campaign->currency }} {{ number_format($campaign->daily_budget) }}/day</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">{{ $campaign->currency }} {{ number_format($r['spend'], 2) }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold tabular-nums text-slate-900">{{ $r['orders'] }}</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">Rs {{ number_format($r['revenue']) }}</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">{{ $r['roas'] !== null ? $r['roas'].'×' : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell align="right">
                            <a href="{{ route('marketing.ads.show', $campaign) }}" wire:navigate><x-ui.button size="sm" variant="secondary">Open</x-ui.button></a>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <div class="mt-4">{{ $campaigns->links() }}</div>
        @endif
    </div>
</div>
