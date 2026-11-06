@props(['active'])

<nav class="mb-5 flex gap-2 overflow-x-auto pb-1" aria-label="Ledger sections">
    @foreach (array_filter([
        ['label' => 'Trial Balance', 'route' => 'ledger.trial-balance'],
        ['label' => 'Profit & Loss', 'route' => 'ledger.profit-and-loss'],
        ['label' => 'Balance Sheet', 'route' => 'ledger.balance-sheet'],
        ['label' => 'Cash Book', 'route' => 'ledger.cash-book'],
        \App\Support\BranchContext::current()->hasMultipleBranches(auth()->user()->shop_id) ? ['label' => 'Branch Profit', 'route' => 'ledger.branch-profit'] : null,
        ['label' => 'Ledger Health', 'route' => 'ledger.health'],
        auth()->user()->is_owner ? ['label' => 'Owner & Held Money', 'route' => 'ledger.owner-money'] : null,
    ]) as $link)
        <a href="{{ route($link['route']) }}" wire:navigate @class([
            'shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
            'border-brand-600 bg-brand-600 text-white' => $active === $link['route'],
            'border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $active !== $link['route'],
        ])>{{ $link['label'] }}</a>
    @endforeach
</nav>
