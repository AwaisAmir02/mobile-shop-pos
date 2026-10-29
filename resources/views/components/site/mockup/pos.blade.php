<div class="bg-white p-4 text-left sm:p-5" aria-hidden="true">
    <div class="flex items-center justify-between">
        <p class="text-xs font-semibold text-slate-900">New sale</p>
        <span class="rounded-md border border-slate-200 px-2 py-1 text-[10px] text-slate-400">Scan barcode or search…</span>
    </div>

    <div class="mt-3 overflow-hidden rounded-lg border border-slate-200">
        <div class="grid grid-cols-12 bg-slate-50 px-3 py-1.5 text-[9px] font-semibold uppercase tracking-wide text-slate-400">
            <span class="col-span-6">Item</span><span class="col-span-2 text-center">Qty</span><span class="col-span-4 text-right">Amount</span>
        </div>
        @foreach ([['Basmati rice · 5 kg', 'Batch B-118 · cost Rs 1,650', 2, '3,700'], ['Cooking oil · 5 L', 'Batch B-121 · cost Rs 2,950', 1, '3,250'], ['Black tea · 950 g', 'Batch B-109 · cost Rs 1,480', 1, '1,650']] as [$name, $batch, $qty, $amount])
            <div class="grid grid-cols-12 items-center border-t border-slate-100 px-3 py-2">
                <div class="col-span-6 min-w-0">
                    <p class="truncate text-[11px] font-medium text-slate-800">{{ $name }}</p>
                    <p class="truncate text-[9px] text-slate-400">{{ $batch }}</p>
                </div>
                <span class="col-span-2 text-center text-[11px] tabular-nums text-slate-600">{{ $qty }}</span>
                <span class="col-span-4 text-right text-[11px] font-semibold tabular-nums text-slate-900">Rs {{ $amount }}</span>
            </div>
        @endforeach
    </div>

    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="space-y-1 rounded-lg bg-slate-50 p-3 text-[10px]">
            <div class="flex justify-between text-slate-500"><span>Subtotal</span><span class="tabular-nums">Rs 8,600</span></div>
            <div class="flex justify-between text-slate-500"><span>Discount</span><span class="tabular-nums">− Rs 100</span></div>
            <div class="flex justify-between border-t border-slate-200 pt-1 text-xs font-bold text-slate-900"><span>Total</span><span class="tabular-nums">Rs 8,500</span></div>
            <div class="flex justify-between text-emerald-600"><span>Profit (FIFO)</span><span class="tabular-nums">Rs 770</span></div>
        </div>
        <div class="flex flex-col justify-between gap-2">
            <div class="flex gap-1.5 text-[10px] font-semibold">
                <span class="flex-1 rounded-md bg-brand-600 py-1.5 text-center text-white">Cash</span>
                <span class="flex-1 rounded-md border border-slate-200 py-1.5 text-center text-slate-600">Partial</span>
                <span class="flex-1 rounded-md border border-slate-200 py-1.5 text-center text-slate-600">Udhaar</span>
            </div>
            <span class="rounded-md bg-accent-500 py-2 text-center text-[11px] font-semibold text-white shadow-sm shadow-accent-500/30">Complete sale &amp; print invoice</span>
        </div>
    </div>
</div>
