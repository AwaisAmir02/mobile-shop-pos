@props([
    // CourierKey::cases(), the bound manualCourier value, and the sale
    'couriers',
    'manualCourier' => '',
    'sale',
    // No connected courier: the only way to send, so it carries the delivery details too.
    'standalone' => false,
])

{{-- Online sellers only (OnlineTool::Delivery): a parcel booked on the courier's
     own portal, typed in with its tracking number. Binds manualCourier,
     manualCourierName, manualTracking (and name, phone, address when standalone);
     submits recordManual. --}}
<form wire:submit="recordManual" data-manual-parcel>
    <x-ui.card title="Booked on the courier's own portal?" description="Add its tracking number here to follow it, and to collect its cash from the courier's payout.">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            @if ($standalone)
                <x-ui.field label="Name" name="name" for="manualName">
                    <x-ui.input wire:model="name" id="manualName" />
                </x-ui.field>
                <x-ui.field label="Mobile number" name="phone" for="manualPhone">
                    <x-ui.input wire:model="phone" id="manualPhone" type="tel" inputmode="tel" />
                </x-ui.field>
                <x-ui.field label="Address" name="address" for="manualAddress" class="sm:col-span-2">
                    <x-ui.input wire:model="address" id="manualAddress" />
                </x-ui.field>
            @endif
            <x-ui.field label="Courier" name="manualCourier" for="manualCourier">
                <x-ui.select wire:model.live="manualCourier" id="manualCourier">
                    <option value="">Choose…</option>
                    @foreach ($couriers as $courier)
                        <option value="{{ $courier->value }}">{{ $courier->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
            <x-ui.field label="Tracking number" name="manualTracking" for="manualTracking">
                <x-ui.input wire:model="manualTracking" id="manualTracking" maxlength="60" />
            </x-ui.field>
            @if ($manualCourier === \App\Enums\CourierKey::Other->value)
                <x-ui.field label="Courier name" name="manualCourierName" for="manualCourierName" class="sm:col-span-2">
                    <x-ui.input wire:model="manualCourierName" id="manualCourierName" maxlength="60" placeholder="e.g. BlueEx, Call Courier, Rider" />
                </x-ui.field>
            @endif
        </div>
        <p class="mt-3 text-xs text-ink-faint">Cash to collect: Rs {{ number_format($sale->amountDue(), 2) }}, what's still owed on {{ $sale->invoiceNumber() }}.</p>
        <div class="mt-4 flex justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="recordManual">Add parcel</x-ui.button>
        </div>
    </x-ui.card>
</form>
