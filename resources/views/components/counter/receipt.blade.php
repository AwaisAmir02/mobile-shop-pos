@props([
    'number',
    'total',
    'lines' => [],
    'download' => true,
])

{{--
    "Saved" card shown after a counter entry. Log Another takes focus so Enter
    starts the next entry.

    lines: [['label' => 'Amount Loaded', 'value' => 'Rs 500.00', 'total' => false], ...]
--}}
<x-ui.card class="mx-auto max-w-lg">
    <div class="flex flex-col items-center text-center">
        <div class="flex h-12 w-12 items-center justify-center rounded-full bg-success-soft text-success">
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
            </svg>
        </div>

        <p class="mt-3 text-sm font-semibold uppercase tracking-wide text-success">Saved</p>
        <p class="mt-1 text-sm text-ink-faint">{{ $number }}</p>
        <p class="text-display-sm tabular-nums text-ink">Rs {{ number_format((float) $total, 2) }}</p>

        @if ($slot->isNotEmpty())
            <div class="mt-1 flex flex-wrap items-center justify-center gap-1.5 text-sm text-ink-faint">{{ $slot }}</div>
        @endif

        @if (count($lines))
            <dl class="mt-4 w-full space-y-1.5 rounded-xl border border-line p-4 text-left text-sm">
                @foreach ($lines as $line)
                    <div @class([
                        'flex justify-between gap-3',
                        'border-t border-line pt-1.5 font-semibold text-ink' => $line['total'] ?? false,
                    ])>
                        <dt @class(['text-ink-faint' => ! ($line['total'] ?? false)])>{{ $line['label'] }}</dt>
                        <dd class="font-medium tabular-nums text-ink">{{ $line['value'] }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        <div class="mt-6 flex w-full gap-3">
            @if ($download)
                <x-ui.button type="button" variant="secondary" wire:click="downloadReceipt" class="flex-1 justify-center">
                    Download Receipt
                </x-ui.button>
            @endif

            <x-ui.button type="button" wire:click="logAnother" x-init="$nextTick(() => $el.focus())" class="flex-1 justify-center">
                Log Another
            </x-ui.button>
        </div>
    </div>
</x-ui.card>
