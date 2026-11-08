<?php

use App\Support\ModuleNav;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Every report in one place, grouped the way an owner looks for them. Reading
 * only: each card links to an existing screen and shows under the same rule
 * that screen's sidebar item or old link used, so no one sees a card they
 * can't open.
 */
new #[Layout('layouts.app')] #[Title('Reports')] class extends Component
{
    public function with(): array
    {
        $user = Auth::user();
        $shop = $user->shop;
        $ledger = $user->hasAccessTo('ledger-reports') && $shop?->ledger_enabled;

        $groups = [
            'Sales & profit' => [
                ['title' => 'Screen reports', 'text' => 'Filter, total and export the records of any screen: sales, stock in, loads, bills, repairs and more.', 'route' => 'reports.screens', 'icon' => 'table', 'show' => true],
                ['title' => 'Gross profit', 'text' => 'Revenue against the cost of goods sold, product by product.', 'route' => 'profit.index', 'icon' => 'trend', 'show' => $user->hasAccessTo('sales-view-cost-price')],
                ['title' => 'Sales history', 'text' => 'Every sale with its payment status, customer and invoice.', 'route' => 'sales.history', 'icon' => 'cart', 'show' => $user->hasAccessTo('sales')],
            ],
            'Stock' => [
                ['title' => 'Stock In receipts', 'text' => 'Goods received, what they cost and what is still owed to vendors.', 'route' => 'stock-ins.history', 'icon' => 'box', 'show' => $user->hasAccessTo('stock-ins')],
                ['title' => 'Expiring & expired stock', 'text' => 'Batches past or near their expiry date, valued at cost.', 'route' => 'stock-ins.expiry', 'icon' => 'clock', 'show' => $user->hasAccessTo('stock-ins') && $shop?->expiry_tracking_enabled],
                ['title' => 'Stock write-offs', 'text' => 'Damaged, lost and expired stock taken off the shelf.', 'route' => 'stock-ins.write-offs', 'icon' => 'minus', 'show' => $user->hasAccessTo('stock-ins')],
            ],
            'People' => [
                ['title' => 'Party ledger', 'text' => 'A running statement for any customer or vendor.', 'route' => 'party-ledger.index', 'icon' => 'book', 'show' => $user->hasAccessTo('party-ledger')],
                ['title' => 'Udhaar', 'text' => 'Who owes the shop, and how much.', 'route' => 'udhaar.index', 'icon' => 'coins', 'show' => $user->hasAccessTo('udhaar')],
                ['title' => 'Payroll history', 'text' => 'Payslips and salaries paid, month by month.', 'route' => 'payroll.history', 'icon' => 'briefcase', 'show' => ModuleNav::payrollHistoryVisible($user)],
            ],
            'Accounts' => [
                ['title' => 'Profit & loss', 'text' => 'Income and expenses for any period, from the ledger.', 'route' => 'ledger.profit-and-loss', 'icon' => 'trend', 'show' => $ledger],
                ['title' => 'Balance sheet', 'text' => 'What the shop owns and owes on any date.', 'route' => 'ledger.balance-sheet', 'icon' => 'scale', 'show' => $ledger],
                ['title' => 'Trial balance', 'text' => 'Every ledger account and its balance.', 'route' => 'ledger.trial-balance', 'icon' => 'table', 'show' => $ledger],
                ['title' => 'Cash book', 'text' => 'Cash and bank movements, day by day.', 'route' => 'ledger.cash-book', 'icon' => 'wallet', 'show' => $ledger],
                ['title' => 'Expenses', 'text' => 'Rent, bills and running costs.', 'route' => 'expenses.index', 'icon' => 'receipt', 'show' => $user->hasAccessTo('expenses')],
            ],
        ];

        return [
            'groups' => collect($groups)
                ->map(fn ($cards) => array_values(array_filter($cards, fn ($card) => $card['show'])))
                ->filter(),
        ];
    }
}; ?>

@php
    $icons = [
        'table' => 'M3.375 19.5h17.25m-17.25 0a1.125 1.125 0 01-1.125-1.125M3.375 19.5h7.5c.621 0 1.125-.504 1.125-1.125m-9.75 0V5.625m0 12.75v-1.5c0-.621.504-1.125 1.125-1.125m18.375 2.625V5.625m0 12.75c0 .621-.504 1.125-1.125 1.125m1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125m0 3.75h-7.5A1.125 1.125 0 0112 18.375m9.75-12.75c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125m19.5 0v1.5c0 .621-.504 1.125-1.125 1.125M2.25 5.625v1.5c0 .621.504 1.125 1.125 1.125m0 0h17.25m-17.25 0h7.5c.621 0 1.125.504 1.125 1.125M3.375 8.25c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125m17.25-3.75h-7.5c-.621 0-1.125.504-1.125 1.125m8.625-1.125c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125M12 10.875v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 10.875c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125M13.125 12h7.5m-7.5 0c-.621 0-1.125.504-1.125 1.125M20.625 12c.621 0 1.125.504 1.125 1.125v1.5c0 .621-.504 1.125-1.125 1.125m-17.25 0h7.5M12 14.625v-1.5m0 1.5c0 .621-.504 1.125-1.125 1.125M12 14.625c0 .621.504 1.125 1.125 1.125m-2.25 0c.621 0 1.125.504 1.125 1.125m0 1.5v-1.5m0 0c0-.621.504-1.125 1.125-1.125m0 0h7.5',
        'trend' => 'M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941',
        'cart' => 'M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
        'box' => 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
        'clock' => 'M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'minus' => 'M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z',
        'book' => 'M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25',
        'coins' => 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182.556-.417 1.28-.659 2.003-.659.725 0 1.45.22 2.003.659l.688.516M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'briefcase' => 'M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z',
        'scale' => 'M12 3v17.25m0 0c-1.472 0-2.882.265-4.185.75M12 20.25c1.472 0 2.882.265 4.185.75M18.75 4.97A48.416 48.416 0 0012 4.5c-2.291 0-4.545.16-6.75.47m13.5 0c1.01.143 2.01.317 3 .52m-3-.52l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.988 5.988 0 01-2.031.352 5.988 5.988 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L18.75 4.971zm-16.5.52c.99-.203 1.99-.377 3-.52m0 0l2.62 10.726c.122.499-.106 1.028-.589 1.202a5.989 5.989 0 01-2.031.352 5.989 5.989 0 01-2.031-.352c-.483-.174-.711-.703-.59-1.202L5.25 4.971z',
        'wallet' => 'M21 12a2.25 2.25 0 00-2.25-2.25H15a1.5 1.5 0 00-1.5 1.5v1.5a1.5 1.5 0 001.5 1.5h3.75A2.25 2.25 0 0021 12zM21 12v4.5a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 16.5V7.5A2.25 2.25 0 015.25 5.25h11.25A2.25 2.25 0 0118.75 7.5v2.25',
        'receipt' => 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
    ];
@endphp

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Every report you can open, in one place.">Reports</x-ui.page-header>
    </x-slot>

    <div class="space-y-8">
        @foreach ($groups as $group => $cards)
            <section aria-labelledby="reports-{{ Str::slug($group) }}">
                <h2 id="reports-{{ Str::slug($group) }}" class="mb-3 text-xs font-semibold uppercase tracking-[0.08em] text-ink-faint">{{ $group }}</h2>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($cards as $card)
                        <a
                            href="{{ route($card['route']) }}"
                            wire:navigate
                            data-report-card="{{ $card['route'] }}"
                            class="group flex items-start gap-4 rounded-2xl border border-line bg-surface p-4 shadow-soft transition hover:border-primary/30 hover:shadow-float focus-visible:ring-2 focus-visible:ring-link sm:p-5"
                        >
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-soft text-primary">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="{{ $icons[$card['icon']] }}" />
                                </svg>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center justify-between gap-2">
                                    <span class="text-base font-bold tracking-tight text-ink">{{ $card['title'] }}</span>
                                    <svg class="h-4 w-4 shrink-0 text-ink-faint transition-transform group-hover:translate-x-0.5 group-hover:text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" />
                                    </svg>
                                </span>
                                <span class="mt-1 block text-sm text-ink-faint">{{ $card['text'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
</div>
