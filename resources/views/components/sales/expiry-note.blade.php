@props(['note' => null, 'unit'])

@if ($note)
    @if ($note['expired'] > 0)
        <p class="mt-0.5 text-xs font-medium text-red-600">{{ $unit->amount($note['expired']) }} of this is expired</p>
    @endif
    @if ($note['soonest'])
        <p class="mt-0.5 text-xs text-amber-600">Expires {{ $note['soonest']->format('d M Y') }}</p>
    @endif
@endif
