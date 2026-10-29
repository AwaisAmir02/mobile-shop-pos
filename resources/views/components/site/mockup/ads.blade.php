<div class="bg-white p-4 text-left sm:p-5" aria-hidden="true">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold text-slate-900">Eid cake offer · Lahore</p>
            <p class="text-[10px] text-slate-400">Goal: WhatsApp messages · 7 days</p>
        </div>
        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[9px] font-semibold text-emerald-700 ring-1 ring-emerald-200">
            <span class="relative flex h-1.5 w-1.5"><span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75"></span><span class="relative inline-flex h-1.5 w-1.5 rounded-full bg-emerald-500"></span></span>
            Active
        </span>
    </div>

    <div class="mt-3 flex flex-wrap gap-1.5">
        @foreach (['Facebook', 'Instagram', 'WhatsApp Status'] as $placement)
            <span @class(['rounded-full px-2 py-0.5 text-[9px] font-semibold', 'bg-emerald-600 text-white' => $placement === 'WhatsApp Status', 'bg-slate-100 text-slate-600' => $placement !== 'WhatsApp Status'])>{{ $placement }}</span>
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
        @foreach ([['Spent', 2400, 'Rs ', ''], ['Chats started', 42, '', ''], ['Orders', 18, '', ''], ['Sales from ad', 43200, 'Rs ', '']] as [$label, $value, $prefix, $suffix])
            <div class="rounded-lg border border-slate-200 p-2">
                <p class="text-[8px] font-medium uppercase tracking-wide text-slate-400">{{ $label }}</p>
                <p class="mt-0.5 text-xs font-bold tabular-nums text-slate-900" data-count-to="{{ $value }}" data-count-prefix="{{ $prefix }}">{{ $prefix }}{{ number_format($value) }}</p>
            </div>
        @endforeach
    </div>

    <div class="mt-3 rounded-lg bg-slate-50 p-2.5">
        <div class="flex justify-between text-[9px] text-slate-500"><span>Budget used</span><span class="tabular-nums">Rs 2,400 of Rs 7,000 cap</span></div>
        <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200"><div class="h-full w-[34%] rounded-full bg-gradient-to-r from-brand-500 to-brand-700"></div></div>
        <p class="mt-1.5 text-[9px] text-slate-400">Billed by Meta to your own ad account. Pause or stop anytime.</p>
    </div>
</div>
