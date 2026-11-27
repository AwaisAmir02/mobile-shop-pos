@props([
    // SellsOnline::onlineChannels(), CourierKey::cases(), ParcelRefusals::forPhone() or null
    'channels',
    'couriers',
    'refusals' => null,
    'withDelivery' => false,
    'parcelCourier' => '',
])

{{-- Online sellers only (OnlineTool::Delivery): where the order came from, who
     it goes to, what they pay for delivery, and a parcel already booked on the
     courier's own portal. Binds channel, withDelivery, delivery.*, deliveryCharge,
     parcelCourier, parcelCourierName and parcelTracking. --}}
<div class="space-y-3 rounded-xl border border-line p-3 text-sm" data-online-delivery>
    <x-ui.field label="Order came from" name="channel" for="channel">
        <x-ui.select wire:model="channel" id="channel">
            @foreach ($channels as $channel)
                <option value="{{ $channel->value }}">{{ $channel === \App\Enums\SaleSource::InStore ? 'In person' : ($channel === \App\Enums\SaleSource::Social ? 'Facebook / Instagram' : $channel->label()) }}</option>
            @endforeach
        </x-ui.select>
    </x-ui.field>

    <x-ui.checkbox wire:model.live="withDelivery" label="Send by courier" />

    @if ($withDelivery)
        <div class="space-y-3" data-delivery-details>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Name" name="delivery.name" for="deliveryName">
                    <x-ui.input wire:model="delivery.name" id="deliveryName" maxlength="100" />
                </x-ui.field>
                <x-ui.field label="Phone" name="delivery.phone" for="deliveryPhone">
                    <x-ui.input wire:model.live.debounce.500ms="delivery.phone" id="deliveryPhone" inputmode="tel" maxlength="20" placeholder="0300 1234567" />
                </x-ui.field>
            </div>

            @if ($refusals !== null && $refusals['count'] > 0)
                <div class="rounded-lg bg-danger-soft px-3 py-2 text-danger" data-refusal-warning>
                    <p class="font-semibold">Refused {{ $refusals['count'] }} {{ \Illuminate\Support\Str::plural('time', $refusals['count']) }} before</p>
                    <p class="mt-0.5 text-xs">{{ $refusals['count'] === 1 ? 'A parcel' : 'Parcels' }} to this number came back undelivered{{ $refusals['last'] ? ', last on '.$refusals['last']->format('d M Y') : '' }}. Confirm the order first, or ask for an advance.</p>
                </div>
            @endif

            <x-ui.field label="Address" name="delivery.address" for="deliveryAddress">
                <x-ui.input wire:model="delivery.address" id="deliveryAddress" maxlength="300" placeholder="House, street, area" />
            </x-ui.field>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="City" name="delivery.city" for="deliveryCity">
                    <x-ui.input wire:model="delivery.city" id="deliveryCity" maxlength="60" />
                </x-ui.field>
                <x-ui.field label="Delivery charge" name="deliveryCharge" for="deliveryCharge" help="Added to the bill">
                    <x-ui.input wire:model.live.debounce.400ms="deliveryCharge" id="deliveryCharge" type="number" min="0" step="1" placeholder="0" />
                </x-ui.field>
            </div>

            <div x-data="{ open: @js($parcelCourier !== '') }">
                <button type="button" x-show="! open" x-on:click="open = true" class="font-semibold text-link hover:underline">+ Already booked? Add the tracking number</button>
                <div x-show="open" x-cloak class="grid grid-cols-2 gap-3">
                    <x-ui.field label="Courier" name="parcelCourier" for="parcelCourier">
                        <x-ui.select wire:model.live="parcelCourier" id="parcelCourier">
                            <option value="">Not booked yet</option>
                            @foreach ($couriers as $courier)
                                <option value="{{ $courier->value }}">{{ $courier->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                    <x-ui.field label="Tracking number" name="parcelTracking" for="parcelTracking">
                        <x-ui.input wire:model="parcelTracking" id="parcelTracking" maxlength="60" />
                    </x-ui.field>
                    @if ($parcelCourier === \App\Enums\CourierKey::Other->value)
                        <x-ui.field label="Courier name" name="parcelCourierName" for="parcelCourierName" class="col-span-2">
                            <x-ui.input wire:model="parcelCourierName" id="parcelCourierName" maxlength="60" placeholder="e.g. BlueEx, Call Courier, Rider" />
                        </x-ui.field>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
