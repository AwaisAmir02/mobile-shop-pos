@props(['active'])

<nav class="mb-5 flex gap-2 overflow-x-auto pb-1" aria-label="Ledger sections">
    @foreach ([['label' => 'Trial Balance', 'route' => 'ledger.trial-balance'], ['label' => 'Ledger Health', 'route' => 'ledger.health']] as $link)
        <a href="{{ route($link['route']) }}" wire:navigate @class([
            'shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
            'border-brand-600 bg-brand-600 text-white' => $active === $link['route'],
            'border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $active !== $link['route'],
        ])>{{ $link['label'] }}</a>
    @endforeach
</nav>
