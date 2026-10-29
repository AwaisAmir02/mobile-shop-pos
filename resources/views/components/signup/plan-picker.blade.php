@props(['plans', 'selected', 'legend' => 'Choose your plan'])

<fieldset>
    <legend class="text-sm font-semibold text-slate-900">{{ $legend }}</legend>
    <div @class(['mt-3 grid grid-cols-1 gap-3', 'sm:grid-cols-2' => $plans->count() === 2, 'sm:grid-cols-3' => $plans->count() >= 3])>
        @foreach ($plans as $plan)
            <label wire:key="plan-{{ $plan->id }}" @class([
                'relative flex cursor-pointer flex-col rounded-2xl border p-4 transition',
                'border-brand-500 bg-brand-50/60 ring-2 ring-brand-500' => (int) $selected === $plan->id,
                'border-slate-200 hover:border-brand-200' => (int) $selected !== $plan->id,
            ])>
                <input type="radio" wire:model.live="planId" value="{{ $plan->id }}" class="sr-only">
                <span class="flex items-center justify-between gap-2">
                    <span class="font-semibold text-slate-900">{{ $plan->name }}</span>
                    @if ($plan->is_featured)
                        <span class="rounded-full bg-accent-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent-700">Popular</span>
                    @endif
                </span>
                <span class="mt-2 text-lg font-extrabold tabular-nums text-slate-900">{{ $plan->priceLabel() }} <span class="text-xs font-medium text-slate-500">/ {{ $plan->intervalLabel() }}</span></span>
            </label>
        @endforeach
    </div>
    <x-ui.error :messages="$errors->get('planId')" class="mt-2" />
</fieldset>
