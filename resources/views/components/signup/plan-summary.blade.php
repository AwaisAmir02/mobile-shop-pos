@props(['plan'])

<aside class="space-y-4 lg:col-span-2">
    @if ($plan)
        <div class="rounded-3xl bg-gradient-to-br from-brand-700 to-brand-900 p-6 text-white shadow-xl shadow-brand-900/20 sm:p-8">
            <p class="text-sm text-brand-100">Your plan</p>
            <p class="mt-1 text-2xl font-extrabold">{{ $plan->name }}</p>
            <p class="mt-1 text-brand-100"><span class="text-xl font-bold text-white">{{ $plan->priceLabel() }}</span> / {{ $plan->intervalLabel() }}</p>
            <ul class="mt-6 space-y-2.5 text-sm">
                @foreach ($plan->featureList() as $feature)
                    <li @class(['flex gap-2.5', 'opacity-50' => ! $feature['included']])>
                        <x-site.icon :name="$feature['included'] ? 'check' : 'close'" class="h-5 w-5 shrink-0 text-accent-300" />
                        <span @class(['line-through' => ! $feature['included']])>@unless ($feature['included'])<span class="sr-only">Not included: </span>@endunless{{ $feature['label'] }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="rounded-3xl border border-slate-200 bg-white p-6 sm:p-8">
        <p class="font-semibold text-slate-900">What happens next</p>
        <ol class="mt-4 space-y-4 text-sm text-slate-600">
            @foreach (['Your shop is created and you’re logged straight in.', 'Pay by bank transfer and enter the transaction reference.', 'We confirm your payment and your subscription becomes active.'] as $i => $item)
                <li class="flex gap-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-xs font-bold text-brand-700">{{ $i + 1 }}</span>
                    {{ $item }}
                </li>
            @endforeach
        </ol>
    </div>
</aside>
