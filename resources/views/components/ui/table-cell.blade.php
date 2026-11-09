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

    // Cells don't wrap unless one asks to. Both classes on one element would let
    // nowrap win (it comes later in the CSS), so a cell asking to wrap drops it.
    $wraps = str_contains((string) $attributes->get('class'), 'whitespace-normal');
@endphp

<td
    @if ($label) data-label="{{ $label }}" @endif
    {{ $attributes->class(["px-4 py-3 text-sm tabular-nums text-ink-muted {$alignment}", 'whitespace-nowrap' => ! $wraps, 'ui-cell-primary' => $primary, 'ui-cell-actions' => $actions]) }}
>
    {{ $slot }}
</td>
