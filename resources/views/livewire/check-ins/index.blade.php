<?php

use App\Actions\CheckIns;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\Toasts;
use App\Models\CheckIn;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Check-ins (gyms, trainers): find a member, see their membership and what
 * they owe, and check them in; fingerprint punches land in the same list.
 */
new #[Layout('layouts.app')] #[Title('Check-ins')] class extends Component
{
    use Toasts;

    public string $search = '';
    public string $scan = '';

    /** A member card or staff badge QR, typed in by the scanner (CheckIns::scan). */
    public function scanCode(): void
    {
        try {
            $result = CheckIns::scan(Auth::user()->shop_id, $this->scan, Auth::id());

            if ($result['kind'] === 'staff') {
                $this->toastSuccess($result['name'].' clocked.');
            } else {
                $checkIn = $result['checkIn'];
                $checkIn->standing->needsAttention() || (float) $checkIn->dues > 0
                    ? $this->toastError($result['name'].' checked in: '.strtolower($checkIn->standing->label()).((float) $checkIn->dues > 0 ? ', owes Rs '.number_format((float) $checkIn->dues, 0) : '').'.')
                    : $this->toastSuccess($result['name'].' checked in.');
                $this->lookupId = $checkIn->customer_id;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->toastError(collect($e->errors())->flatten()->first());
        }

        $this->scan = '';
    }

    public function card(int $customerId, \App\Services\CheckInCardPdfService $pdf): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $customer = Customer::findOrFail($customerId);
        \App\Actions\Memberships::assignMemberNo($customer);

        return $pdf->download($customer->fresh());
    }
    public ?int $lookupId = null;

    public function look(int $customerId): void
    {
        $this->lookupId = $customerId;
        $this->search = '';
    }

    public function checkIn(): void
    {
        $customer = Customer::findOrFail($this->lookupId);
        $checkIn = CheckIns::atDesk($customer, Auth::id());
        $this->lookupId = null;
        $checkIn->standing->needsAttention() || (float) $checkIn->dues > 0
            ? $this->toastError($customer->name.' checked in: '.strtolower($checkIn->standing->label()).((float) $checkIn->dues > 0 ? ', owes Rs '.number_format((float) $checkIn->dues, 0) : '').'.')
            : $this->toastSuccess($customer->name.' checked in.');
    }

    public function with(): array
    {
        $today = now('Asia/Karachi');
        $search = trim($this->search);

        return [
            'matches' => $search !== '' ? Customer::query()->where(fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')->orWhere('member_no', $search))->orderBy('name')->limit(8)->get() : collect(),
            'lookup' => $this->lookupId ? Customer::find($this->lookupId) : null,
            'standing' => $this->lookupId && ($c = Customer::find($this->lookupId)) ? CheckIns::lookup($c, $today->toDateString()) : null,
            'today' => CheckIn::query()->with('customer')->whereBetween('checked_in_at', [$today->copy()->startOfDay()->utc(), $today->copy()->endOfDay()->utc()])->latest('checked_in_at')->get(),
            'biometricOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Biometric),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Members coming in today, at the desk or by fingerprint">Check-ins</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Check someone in" data-desk-check-in>
            <form wire:submit="scanCode" class="mb-3 flex gap-2" data-qr-scan>
                <x-ui.input wire:model="scan" placeholder="Scan a member card or staff badge" autocomplete="off" aria-label="Scanned code" />
                <x-ui.button type="submit" variant="secondary">Go</x-ui.button>
            </form>
            <x-ui.input wire:model.live.debounce.250ms="search" type="search" placeholder="Name, phone or member no." autofocus aria-label="Find a member" />
            @if ($matches->isNotEmpty())
                <ul class="mt-2 divide-y divide-line rounded-xl border border-line">
                    @foreach ($matches as $customer)
                        <li><button type="button" wire:click="look({{ $customer->id }})" class="w-full px-3 py-2 text-left text-sm hover:bg-surface-sunken">{{ $customer->name }} <span class="text-xs text-ink-faint">{{ $customer->phone }}</span></button></li>
                    @endforeach
                </ul>
            @endif
            @if ($lookup && $standing)
                <div @class(['mt-4 rounded-xl border p-4', 'border-success/30 bg-success-soft' => ! $standing['standing']->needsAttention() && $standing['dues'] <= 0, 'border-danger/30 bg-danger-soft' => $standing['standing']->needsAttention() || $standing['dues'] > 0]) data-member-standing="{{ $standing['standing']->value }}">
                    <p class="text-base font-bold text-ink">{{ $lookup->name }}</p>
                    <p class="text-sm text-ink">{{ $standing['standing']->label() }}@if ($standing['membership']) · {{ $standing['membership']->plan_name }}@if ($standing['membership']->ends_on) until {{ $standing['membership']->ends_on->format('d M Y') }}@endif @endif</p>
                    @if ($standing['dues'] > 0)
                        <p class="mt-1 text-sm font-semibold text-danger" data-member-dues>Owes Rs {{ number_format($standing['dues'], 2) }}</p>
                    @endif
                    <div class="mt-3 flex gap-2">
                        <x-ui.button wire:click="checkIn" data-check-in>Check in</x-ui.button>
                        <x-ui.button variant="secondary" wire:click="card({{ $lookup->id }})" data-member-card>Card</x-ui.button>
                        @if ($standing['membership']?->membership_plan_id && $standing['standing']->needsAttention())
                            <x-ui.button variant="secondary" href="{{ route('sales.index', ['renew' => $standing['membership']->id]) }}">Renew</x-ui.button>
                        @endif
                    </div>
                </div>
            @endif
        </x-ui.card>

        <div class="lg:col-span-2">
            <x-ui.card :title="'Today · '.$today->count()" :padding="false" data-check-ins-today>
                @if ($biometricOn)
                    <x-slot name="actions"><x-ui.button size="sm" variant="secondary" href="{{ route('biometric-devices.index') }}" wire:navigate>Fingerprint devices</x-ui.button></x-slot>
                @endif
                @if ($today->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-faint">Nobody yet today.</p>
                @else
                    <x-ui.table :headers="['Time', 'Member', 'How', 'Standing', 'Owed']">
                        @foreach ($today as $checkIn)
                            <tr wire:key="checkin-{{ $checkIn->id }}">
                                <td class="px-4 py-2 text-sm text-ink-muted">{{ $checkIn->checked_in_at->timezone('Asia/Karachi')->format('g:i A') }}</td>
                                <td class="px-4 py-2 text-sm font-semibold text-ink">{{ $checkIn->customer?->name ?? 'Unknown no. '.$checkIn->device_pin }}</td>
                                <td class="px-4 py-2 text-sm text-ink-muted">{{ $checkIn->source->label() }}</td>
                                <td class="px-4 py-2 text-sm"><x-ui.badge :variant="$checkIn->standing->needsAttention() ? 'danger' : 'success'">{{ $checkIn->standing->label() }}</x-ui.badge></td>
                                <td class="px-4 py-2 text-sm tabular-nums {{ (float) $checkIn->dues > 0 ? 'font-semibold text-danger' : 'text-ink-faint' }}">{{ (float) $checkIn->dues > 0 ? 'Rs '.number_format((float) $checkIn->dues, 0) : '—' }}</td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        </div>
    </div>
</div>
