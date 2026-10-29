@php
    $bars = [38, 52, 44, 61, 57, 72, 66, 84, 70, 92, 78, 100];
    $nav = [['chart', 'Dashboard', true], ['cart', 'Sales', false], ['box', 'Stock In', false], ['wallet', 'Udhaar', false], ['users', 'Customers', false], ['chat', 'WhatsApp Orders', false], ['megaphone', 'Marketing', false]];
@endphp

<div class="flex bg-slate-50 text-left" aria-hidden="true">
    <aside class="hidden w-40 shrink-0 border-r border-slate-200 bg-white p-3 sm:block">
        <img src="{{ asset(config('branding.logo_horizontal')) }}" alt="" class="mb-4 h-5 w-auto" loading="lazy">
        <ul class="space-y-0.5">
            @foreach ($nav as [$icon, $label, $active])
                <li @class(['flex items-center gap-2 rounded-md px-2 py-1.5 text-[10px] font-medium', 'bg-brand-50 text-brand-700' => $active, 'text-slate-500' => ! $active])>
                    <x-site.icon :name="$icon" class="h-3.5 w-3.5" /> {{ $label }}
                </li>
            @endforeach
        </ul>
    </aside>

    <div class="min-w-0 flex-1 p-3 sm:p-4">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-[11px] font-semibold text-slate-900 sm:text-xs">Dashboard</p>
                <p class="text-[9px] text-slate-400">Today · Madina General Store</p>
            </div>
            <span class="rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-semibold text-emerald-700 ring-1 ring-emerald-200">Live</span>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2 lg:grid-cols-4">
            @foreach ([['Sales Revenue', 'Rs 148,500', '+12%'], ['Items Sold', '412', '96 sales'], ['Udhaar Outstanding', 'Rs 41,200', '23 customers'], ['Expenses', 'Rs 18,900', 'This month']] as [$label, $value, $sub])
                <div class="rounded-lg border border-slate-200 bg-white p-2">
                    <p class="text-[8px] font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
                    <p class="mt-0.5 text-[11px] font-bold tabular-nums text-slate-900 sm:text-xs">{{ $value }}</p>
                    <p class="text-[8px] text-slate-400">{{ $sub }}</p>
                </div>
            @endforeach
        </div>

        <div class="mt-2 grid grid-cols-1 gap-2 lg:grid-cols-5">
            <div class="rounded-lg border border-slate-200 bg-white p-2.5 lg:col-span-3" data-reveal>
                <p class="text-[9px] font-semibold text-slate-700">Revenue — last 12 days</p>
                <div class="mt-2 flex h-20 items-end gap-1 sm:h-24">
                    @foreach ($bars as $i => $height)
                        <div class="site-bar flex-1 rounded-t bg-gradient-to-t from-brand-600 to-brand-400" style="height: {{ $height }}%; --bar-delay: {{ $i * 60 }}ms"></div>
                    @endforeach
                </div>
            </div>
            <div class="rounded-lg border border-slate-200 bg-white p-2.5 lg:col-span-2">
                <p class="text-[9px] font-semibold text-slate-700">Latest activity</p>
                <ul class="mt-1.5 space-y-1.5">
                    @foreach ([['bg-emerald-500', 'WhatsApp order #1042', 'Rs 2,400'], ['bg-brand-500', 'Sale INV-2381', 'Rs 3,300'], ['bg-accent-500', 'Udhaar received', 'Rs 4,350'], ['bg-violet-500', 'Ad: 6 new chats', 'Lahore']] as [$dot, $text, $amount])
                        <li class="flex items-center justify-between gap-2 text-[9px]">
                            <span class="flex min-w-0 items-center gap-1.5 text-slate-600"><span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}"></span><span class="truncate">{{ $text }}</span></span>
                            <span class="shrink-0 font-semibold tabular-nums text-slate-800">{{ $amount }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</div>
