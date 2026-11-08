@props([
    'total' => 0,
    'totalLabel' => 'Total',
    'lines' => [],
    'status' => null,
    'owed' => null,
    'owedLabel' => 'Still Owed by Customer',
    'action' => 'save',
    'totalExpression' => null,
])

{{--
    Sticky summary for x-counter.form. Every figure here is one the screen
    already calculated; this only lays them out.

    lines:  [['label' => 'Service Charge', 'value' => 'Rs 20.00'], ...]
    status: a PaymentStatus to preview ("Will be recorded as"), or null
    owed:   amount still owed, or null to hide the row
    totalExpression: optional Alpine expression that keeps the total live
                     between server round trips (Services' deferred amount)
--}}
<x-ui.card {{ $attributes }}>
    <p class="text-sm font-medium text-ink-muted">{{ $totalLabel }}</p>
    <p class="mt-1 text-3xl font-extrabold tracking-tight tabular-nums text-ink" data-counter-total @if ($totalExpression) x-text="{{ $totalExpression }}" @endif>Rs {{ number_format((float) $total, 2) }}</p>

    @if (count($lines))
        <dl class="mt-4 space-y-1.5 border-t border-line pt-3 text-sm">
            @foreach ($lines as $line)
                <div class="flex justify-between gap-3">
                    <dt class="text-ink-faint">{{ $line['label'] }}</dt>
                    <dd class="font-medium tabular-nums text-ink">{{ $line['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($status || $owed !== null)
        <div class="mt-3 space-y-1.5 border-t border-line pt-3 text-sm">
            @if ($status)
                <div class="flex items-center justify-between gap-3">
                    <span class="text-ink-faint">Will be recorded as</span>
                    <x-ui.badge :variant="match($status->value) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }">
                        {{ $status->label() }}
                    </x-ui.badge>
                </div>
            @endif
            @if ($owed !== null)
                <div class="flex items-center justify-between gap-3">
                    <span class="text-ink-faint">{{ $owedLabel }}</span>
                    <span class="font-semibold tabular-nums text-warning">Rs {{ number_format($owed, 2) }}</span>
                </div>
            @endif
        </div>
    @endif

    {{ $slot }}

    <x-ui.button type="submit" size="lg" class="mt-5 w-full justify-center" wire:loading.attr="disabled" wire:target="{{ $action }}">
        Save
    </x-ui.button>
</x-ui.card>
