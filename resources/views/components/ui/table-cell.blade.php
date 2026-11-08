@props(['align' => 'left'])

@php
    $alignment = [
        'left' => 'text-left',
        'right' => 'text-right',
        'center' => 'text-center',
    ][$align] ?? 'text-left';
@endphp

<td {{ $attributes->class(["px-4 py-3 text-sm tabular-nums text-ink-muted whitespace-nowrap {$alignment}"]) }}>
    {{ $slot }}
</td>
