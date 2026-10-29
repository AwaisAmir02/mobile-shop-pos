@props(['meters'])

<ul class="space-y-4">
    @foreach ($meters as $meter)
        <li>
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="font-medium text-slate-700">{{ $meter['label'] }}</span>
                <span @class([
                    'tabular-nums',
                    'text-slate-500' => $meter['state'] === 'ok',
                    'font-semibold text-amber-700' => $meter['state'] === 'warning',
                    'font-semibold text-red-700' => $meter['state'] === 'full',
                ])>
                    {{ number_format($meter['used']) }} / {{ $meter['limit'] === null ? 'Unlimited' : number_format($meter['limit']) }}
                </span>
            </div>
            @if ($meter['limit'] !== null)
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $meter['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $meter['label'] }}">
                    <div @class([
                        'h-full rounded-full',
                        'bg-brand-500' => $meter['state'] === 'ok',
                        'bg-amber-500' => $meter['state'] === 'warning',
                        'bg-red-500' => $meter['state'] === 'full',
                    ]) style="width: {{ $meter['percent'] }}%"></div>
                </div>
                @if ($meter['over'] && $meter['key'] === 'sales_per_month')
                    <p class="mt-1 text-xs text-red-700">Over the plan’s sales limit — sales keep working, but the plan needs upgrading.</p>
                @endif
            @endif
        </li>
    @endforeach
</ul>
