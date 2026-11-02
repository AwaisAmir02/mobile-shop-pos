{{-- remaining/packs/canBuy are only used by the owner's Plan & Usage screen. --}}
@props(['meters', 'remaining' => false, 'packs' => [], 'canBuy' => true])

<ul class="space-y-4">
    @foreach ($meters as $meter)
        <li>
            <div class="flex items-baseline justify-between gap-3 text-sm">
                <span class="font-medium text-slate-700">{{ $meter['label'] }}</span>
                <span @class([
                    'tabular-nums',
                    'text-slate-500' => $meter['state'] === 'ok',
                    'font-semibold text-amber-700' => $meter['state'] === 'warning' || ($meter['state'] === 'full' && ! ($meter['alarm'] ?? true)),
                    'font-semibold text-red-700' => $meter['state'] === 'full' && ($meter['alarm'] ?? true),
                ])>
                    {{ number_format($meter['used']) }} / {{ $meter['limit'] === null ? 'Unlimited' : number_format($meter['limit']) }}
                </span>
            </div>
            @if ($meter['limit'] !== null)
                <div class="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow="{{ $meter['percent'] }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ $meter['label'] }}">
                    <div @class([
                        'h-full rounded-full',
                        'bg-brand-500' => $meter['state'] === 'ok',
                        'bg-amber-500' => $meter['state'] === 'warning' || ($meter['state'] === 'full' && ! ($meter['alarm'] ?? true)),
                        'bg-red-500' => $meter['state'] === 'full' && ($meter['alarm'] ?? true),
                    ]) style="width: {{ $meter['percent'] }}%"></div>
                </div>
                @if ($meter['over'] && $meter['key'] === 'sales_per_month')
                    <p class="mt-1 text-xs text-red-700">Over the plan’s sales limit — sales keep working, but the plan needs upgrading.</p>
                @endif
            @endif
            @if ($remaining)
                @php($pack = $meter['limit'] === null ? null : ($packs[$meter['key']] ?? null))
                <div class="mt-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span class="text-slate-500">
                        @if ($meter['limit'] === null)
                            Unlimited on your plan
                        @elseif ($meter['over'])
                            {{ number_format($meter['used'] - $meter['limit']) }} over the limit
                        @else
                            {{ number_format($meter['limit'] - $meter['used']) }} left
                        @endif
                    </span>
                    @if ($pack)
                        <x-ui.button size="sm" variant="secondary" wire:click="buyAddon({{ $pack->id }})" wire:confirm="Buy {{ $pack->name }} ({{ $pack->grants() }}) for {{ $pack->priceLabel() }}? We'll create an invoice for you to pay by bank transfer." :disabled="! $canBuy">Add more</x-ui.button>
                    @endif
                </div>
            @endif
        </li>
    @endforeach
</ul>
