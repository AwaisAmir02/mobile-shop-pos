@props([
    'align' => 'left',
    // Phone card layout (x-ui.table stack): the label shown beside the value,
    // the card's title cell, and the actions cell.
    'label' => null,
    'primary' => false,
    'actions' => false,
])

@php
    $alignment = [
        'left' => 'text-left',
        'right' => 'text-right',
        'center' => 'text-center',
    ][$align] ?? 'text-left';
@endphp

<td
    @if ($label) data-label="{{ $label }}" @endif
    {{ $attributes->class(["px-4 py-3 text-sm tabular-nums text-ink-muted whitespace-nowrap {$alignment}", 'ui-cell-primary' => $primary, 'ui-cell-actions' => $actions]) }}
>
    {{ $slot }}
</td>
