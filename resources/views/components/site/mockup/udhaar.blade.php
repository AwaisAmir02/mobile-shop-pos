<div class="bg-white p-4 text-left sm:p-5" aria-hidden="true">
    <div class="flex items-start justify-between gap-3">
        <div class="flex items-center gap-2.5">
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-[11px] font-bold text-brand-700">BA</span>
            <div>
                <p class="text-xs font-semibold text-slate-900">Bilal Ahmed</p>
                <p class="text-[10px] text-slate-400">0300 1112223 · Customer since Mar</p>
            </div>
        </div>
        <div class="text-right">
            <p class="text-[9px] font-medium uppercase tracking-wide text-slate-400">Total due</p>
            <p class="text-base font-extrabold tabular-nums text-rose-600" data-count-to="11350" data-count-prefix="Rs ">Rs 11,350</p>
        </div>
    </div>

    <ul class="mt-4 divide-y divide-slate-100 rounded-lg border border-slate-200">
        @foreach ([['wallet', 'Udhaar given', 'Cash taken on 12 Oct', '2,000'], ['receipt', 'Sale INV-2204', 'Unpaid balance', '5,000'], ['receipt', 'Sale INV-2231', 'Monthly grocery · balance', '4,350']] as [$icon, $source, $detail, $amount])
            <li class="flex items-center justify-between gap-3 px-3 py-2.5">
                <div class="flex min-w-0 items-center gap-2.5">
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-slate-100 text-slate-500"><x-site.icon :name="$icon" class="h-3.5 w-3.5" /></span>
                    <div class="min-w-0">
                        <p class="truncate text-[11px] font-medium text-slate-800">{{ $source }}</p>
                        <p class="truncate text-[9px] text-slate-400">{{ $detail }}</p>
                    </div>
                </div>
                <span class="shrink-0 text-[11px] font-semibold tabular-nums text-slate-900">Rs {{ $amount }}</span>
            </li>
        @endforeach
    </ul>

    <div class="mt-3 flex gap-2 text-[10px] font-semibold">
        <span class="flex-1 rounded-md border border-slate-200 py-1.5 text-center text-slate-600">Receive payment</span>
        <span class="flex flex-1 items-center justify-center gap-1 rounded-md bg-emerald-600 py-1.5 text-white"><x-site.icon name="chat" class="h-3 w-3" /> WhatsApp reminder</span>
    </div>
</div>
