@props(['align' => 'left'])

@php
    $alignment = [
        'left' => 'text-left',
        'right' => 'text-right',
        'center' => 'text-center',
    ][$align] ?? 'text-left';
@endphp

<td {{ $attributes->class(["px-4 py-3.5 text-sm text-slate-700 whitespace-nowrap {$alignment}"]) }}>
    {{ $slot }}
</td>
