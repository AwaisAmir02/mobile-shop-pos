<?php

use App\Services\Marketing\MarketingStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Marketing')] class extends Component
{
    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $shop = Auth::user()->shop;
        abort_unless($shop?->whatsapp_enabled || $shop?->social_enabled, 403);

        $this->from = now()->subDays(29)->toDateString();
        $this->to = now()->toDateString();
    }

    public function with(): array
    {
        $from = rescue(fn () => Carbon::parse($this->from), now()->subDays(29), false);
        $to = rescue(fn () => Carbon::parse($this->to), now(), false);
        $stats = new MarketingStats($from, $to);

        return [
            'sales' => $stats->salesBySource(),
            'funnel' => $stats->whatsappFunnel(),
            'attribution' => $stats->attribution(),
            'social' => $stats->socialTotals(),
            'reviews' => $stats->reviews(),
            'topPosts' => $stats->topPosts(),
            'ads' => Auth::user()->shop->ads_enabled ? $stats->adTotals() : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Marketing</h1>
    </x-slot>

    <x-marketing.nav active="marketing.index" />

    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
        <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="from" id="from" type="date" />
        </x-ui.field>
        <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="to" id="to" type="date" />
        </x-ui.field>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($sales as $key => $row)
            <x-ui.card :padding="false">
                <div class="px-5 py-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">{{ $row['label'] }} sales</p>
                    <p class="mt-1 text-2xl font-semibold text-slate-900">Rs {{ number_format($row['revenue']) }}</p>
                    <p class="text-sm text-slate-500">{{ $row['orders'] }} {{ \Illuminate\Support\Str::plural('order', $row['orders']) }}</p>
                </div>
            </x-ui.card>
        @endforeach
        <x-ui.card :padding="false">
            <div class="px-5 py-4">
                <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Customer reviews</p>
                <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $reviews['count'] ? $reviews['average'].' ★' : '—' }}</p>
                <p class="text-sm text-slate-500">{{ $reviews['count'] }} {{ \Illuminate\Support\Str::plural('review', $reviews['count']) }}</p>
            </div>
        </x-ui.card>
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <x-ui.card title="WhatsApp orders" description="From cart to accepted sale.">
            @php($steps = ['Carts started' => $funnel['carts'], 'Orders placed' => $funnel['submitted'], 'Accepted' => $funnel['accepted'], 'Rejected' => $funnel['rejected'], 'Waiting for review' => $funnel['pending']])
            <dl class="space-y-2 text-sm">
                @foreach ($steps as $label => $value)
                    <div class="flex justify-between gap-3"><dt class="text-slate-500">{{ $label }}</dt><dd class="font-semibold tabular-nums text-slate-900">{{ $value }}</dd></div>
                @endforeach
                @if ($funnel['submitted'] > 0)
                    <div class="flex justify-between gap-3 border-t border-slate-100 pt-2"><dt class="text-slate-500">Acceptance rate</dt><dd class="font-semibold tabular-nums text-slate-900">{{ round($funnel['accepted'] / $funnel['submitted'] * 100) }}%</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <x-ui.card title="Where WhatsApp sales came from" description="Accepted orders and their sale value, by what brought the customer in.">
            @php($sources = ['posts' => 'Social posts (REF code)', 'qr' => 'In-shop QR codes', 'ads' => 'Click-to-WhatsApp ads', 'direct' => 'Direct / saved number'])
            <dl class="space-y-2 text-sm">
                @foreach ($sources as $key => $label)
                    <div class="flex justify-between gap-3">
                        <dt class="text-slate-500">{{ $label }}</dt>
                        <dd class="tabular-nums text-slate-900"><span class="font-semibold">{{ $attribution[$key]['orders'] }}</span> · Rs {{ number_format($attribution[$key]['revenue']) }}</dd>
                    </div>
                @endforeach
            </dl>
            @if ($ads && $ads['campaigns'] > 0)
                <p class="mt-3 text-xs text-slate-500">Ads: spent {{ number_format($ads['spend'], 2) }} for {{ $ads['orders'] }} {{ \Illuminate\Support\Str::plural('order', $ads['orders']) }} worth Rs {{ number_format($ads['revenue']) }}{{ $ads['roas'] !== null ? ' — '.$ads['roas'].'× return' : '' }}.</p>
            @endif
            <p class="mt-3 text-xs text-slate-400">Social reach in this period: {{ number_format($social['reach']) }} people · {{ number_format($social['engagement']) }} likes/comments/shares across {{ $social['posts'] }} published {{ \Illuminate\Support\Str::plural('placement', $social['posts']) }}.</p>
        </x-ui.card>
    </div>

    <div class="mt-6">
        <h2 class="mb-3 text-base font-semibold text-slate-900">Posts &amp; QR codes</h2>
        @if ($topPosts->isEmpty())
            <x-ui.empty-state title="No posts yet" description="Create a post from a product in Posts & Share kit — every post gets a REF code so you can see which ones bring real orders.">
                <x-slot name="action">
                    <a href="{{ route('marketing.posts') }}" wire:navigate><x-ui.button>Create a post</x-ui.button></a>
                </x-slot>
            </x-ui.empty-state>
        @else
            <x-ui.table :headers="['Post', 'Type', 'Placements', 'Reach', 'Engagement', 'Orders', 'Revenue']">
                @foreach ($topPosts as $row)
                    <x-ui.table-row wire:key="top-{{ $row['post']->id }}">
                        <x-ui.table-cell class="font-medium text-slate-900">
                            <a href="{{ $row['post']->kind === \App\Enums\PostKind::QrCode ? route('marketing.qr') : route('marketing.posts.show', $row['post']) }}" wire:navigate class="hover:text-brand-700">{{ $row['post']->title }}</a>
                            <div class="text-xs text-slate-400">{{ $row['post']->refTag() }}</div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $row['post']->kind->label() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $row['post']->targets->map(fn ($t) => $t->surface->label())->implode(', ') ?: 'Share kit only' }}</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">{{ number_format($row['reach']) }}</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">{{ number_format($row['engagement']) }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold tabular-nums text-slate-900">{{ $row['orders'] }}</x-ui.table-cell>
                        <x-ui.table-cell class="tabular-nums">Rs {{ number_format($row['revenue']) }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </div>
</div>
