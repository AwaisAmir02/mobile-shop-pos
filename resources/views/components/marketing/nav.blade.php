@props(['active'])

@php
    $user = auth()->user();
    $items = collect([
        ['route' => 'marketing.index', 'label' => 'Overview', 'permission' => 'marketing-analytics'],
        ['route' => 'marketing.posts', 'label' => 'Posts & Share kit', 'permission' => 'marketing'],
        ['route' => 'marketing.reviews', 'label' => 'Reviews', 'permission' => 'marketing'],
        ['route' => 'marketing.qr', 'label' => 'QR codes', 'permission' => 'marketing'],
        ['route' => 'marketing.ads', 'label' => 'Ads', 'permission' => 'marketing-ads', 'needs_ads' => true],
    ])->filter(fn ($item) => $user->hasAccessTo($item['permission']) && (! ($item['needs_ads'] ?? false) || $user->shop?->ads_enabled));
@endphp

<div class="mb-5 flex gap-1 overflow-x-auto border-b border-slate-200">
    @foreach ($items as $item)
        @php($isActive = $item['route'] === $active)
        <a href="{{ route($item['route']) }}" wire:navigate @class([
            'whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition',
            'border-brand-600 text-brand-700' => $isActive,
            'border-transparent text-slate-500 hover:text-slate-700' => ! $isActive,
        ])>{{ $item['label'] }}</a>
    @endforeach
</div>
