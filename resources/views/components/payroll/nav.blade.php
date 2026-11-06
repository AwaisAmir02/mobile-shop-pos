@props(['active'])

@php
    $user = auth()->user();
    $links = collect([
        ['label' => 'Run Payroll', 'route' => 'payroll.run', 'permission' => 'payroll-run'],
        ['label' => 'Employees', 'route' => 'payroll.employees', 'permission' => 'payroll'],
        ['label' => 'Advances', 'route' => 'payroll.advances', 'permission' => 'payroll'],
        ['label' => 'History', 'route' => 'payroll.history', 'permission' => 'payroll'],
        ['label' => 'Deduction Types', 'route' => 'payroll.deduction-types', 'permission' => 'payroll'],
    ])->filter(fn ($link) => $user->hasAccessTo($link['permission']));
@endphp

<nav class="mb-5 flex gap-2 overflow-x-auto pb-1" aria-label="Payroll sections">
    @foreach ($links as $link)
        <a href="{{ route($link['route']) }}" wire:navigate @class([
            'shrink-0 whitespace-nowrap rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors',
            'border-brand-600 bg-brand-600 text-white' => $active === $link['route'],
            'border-slate-300 bg-white text-slate-600 hover:bg-slate-50 hover:text-slate-900' => $active !== $link['route'],
        ])>{{ $link['label'] }}</a>
    @endforeach
</nav>

@unless ($user->shop?->payroll_enabled)
    <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        Payroll isn't on your plan right now. Your history is still here, but new payroll can't be run. Salaries already paid stay in your Expenses.
    </div>
@endunless
