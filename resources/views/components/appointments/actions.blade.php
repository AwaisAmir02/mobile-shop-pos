@props(['appointment', 'depositsOn' => false, 'shopName' => ''])

{{-- What can be done with one booking or walk-in, on the Appointments screen. --}}
@php($open = $appointment->status->isOpen())
@php($remind = $appointment->whoPhone() && $appointment->starts_at ? \App\Actions\JobCards::whatsappLink($appointment->whoPhone(), \App\Actions\Appointments::reminderText($appointment, $shopName)) : null)
<div class="mt-1 flex flex-wrap gap-x-2 gap-y-1">
    @if ($open)
        @if ($appointment->status === \App\Enums\AppointmentStatus::Booked)
            <button type="button" wire:click="setStatus({{ $appointment->id }}, 'waiting')" class="font-semibold text-link hover:underline">Arrived</button>
        @endif
        @if ($appointment->status !== \App\Enums\AppointmentStatus::InService)
            <button type="button" wire:click="setStatus({{ $appointment->id }}, 'in_service')" class="font-semibold text-link hover:underline">Start</button>
        @endif
        <a href="{{ route('sales.index', ['appointment' => $appointment->id]) }}" class="font-semibold text-link hover:underline" data-bill-appointment="{{ $appointment->id }}">Bill</a>
        @if (! $appointment->walk_in)
            <button type="button" wire:click="openMove({{ $appointment->id }})" class="text-ink-muted hover:underline">Move</button>
            @if ($remind)
                <a href="{{ $remind }}" target="_blank" rel="noopener" class="text-ink-muted hover:underline">Remind</a>
            @endif
            @if ($depositsOn)
                <button type="button" wire:click="openDeposit({{ $appointment->id }})" class="text-ink-muted hover:underline">Deposit</button>
            @endif
            <button type="button" wire:click="setStatus({{ $appointment->id }}, 'no_show')" class="text-ink-muted hover:underline">No-show</button>
        @endif
        <button type="button" wire:click="setStatus({{ $appointment->id }}, 'cancelled')" wire:confirm="Cancel this {{ $appointment->walk_in ? 'walk-in' : 'booking' }}?" class="text-danger hover:underline">Cancel</button>
    @elseif ($appointment->sale_id)
        <a href="{{ route('sales.show', $appointment->sale_id) }}" class="font-semibold text-link hover:underline">{{ 'INV-'.str_pad((string) $appointment->sale_id, 6, '0', STR_PAD_LEFT) }}</a>
    @endif
    @if ($appointment->deposits->where('status', \App\Enums\RecordStatus::Active)->isNotEmpty())
        <span class="text-success">Deposit Rs {{ number_format($appointment->deposits->where('status', \App\Enums\RecordStatus::Active)->sum(fn ($d) => $d->remaining()), 0) }} held</span>
    @endif
</div>
