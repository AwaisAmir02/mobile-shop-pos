@props([
    'productId',
    'item',
    'lineTotal',
    'expiryNote' => null,
])

{{-- One line of the Sales register / sale edit cart. Binds the same cart.{id}.*
     properties as before; price and discount fold away until needed, but an
     override or a below-cost warning always shows. --}}
@php
    $unit = \App\Enums\QuantityUnit::fromValue($item['unit'] ?? 'piece');
    $hasCustomPrice = $item['custom_price'] !== '' && $item['custom_price'] !== null;
    $overridden = $hasCustomPrice && (float) $item['custom_price'] !== (float) $item['list_price'];
    $belowCost = $overridden && $item['cost_price'] !== null && (float) $item['custom_price'] < (float) $item['cost_price'];
    $discount = (float) ($item['discount'] ?? 0);
    $unitPrice = $hasCustomPrice ? (float) $item['custom_price'] : (float) $item['list_price'];
    $priceError = $errors->has("cart.$productId.custom_price") || $errors->has("cart.$productId.discount");
    $quantity = \App\Support\Quantity::normalize($item['quantity'] === '' ? 0 : $item['quantity']);
    $numberInput = 'rounded-lg border-line-strong bg-surface py-1.5 text-right text-sm text-ink focus:border-link focus:ring-link';
@endphp

<li
    wire:key="cart-{{ $productId }}"
    data-cart-line="{{ $productId }}"
    x-data="{ adjust: @js($overridden || $discount > 0 || $priceError) }"
    class="px-4 py-3.5 sm:px-5"
>
    {{-- Phones: details on their own row, then stepper, total and remove beneath. --}}
    <div class="flex flex-wrap items-start gap-x-3 gap-y-2 sm:flex-nowrap">
        <div class="min-w-0 basis-full sm:basis-0 sm:flex-1">
            <div class="flex items-center gap-1.5">
                <p class="truncate text-sm font-semibold text-ink">{{ $item['name'] }}</p>
                @if ($item['cost_price'] !== null)
                    <x-ui.info-tooltip :text="'Cost: Rs '.number_format($item['cost_price'], 2)" />
                @endif
            </div>
            <p class="text-xs text-ink-faint">
                {{ $item['category'] }} · Rs {{ number_format($unitPrice, 2) }}{{ $unit->perSuffix() }}
                @if ($discount > 0)
                    · Rs {{ number_format($discount, 2) }} off
                @endif
            </p>
            @if ($overridden)
                <p class="mt-0.5 text-xs font-semibold text-warning">Price overridden</p>
                @if ($belowCost)
                    <p class="mt-0.5 text-xs font-semibold text-danger">Below cost price</p>
                @endif
            @endif
            <x-sales.expiry-note :note="$expiryNote" :unit="$unit" />
            <button type="button" x-on:click="adjust = ! adjust" :aria-expanded="adjust.toString()" class="mt-1 text-xs font-semibold text-link hover:underline">
                Price &amp; discount
            </button>
        </div>

        <div class="flex shrink-0 flex-col items-end gap-1">
            @if ($unit->allowsDecimals())
                <div class="flex items-center gap-1.5">
                    <input
                        type="number"
                        min="{{ $unit->inputMin() }}"
                        step="{{ $unit->inputStep() }}"
                        max="{{ $item['max_stock'] }}"
                        wire:model.live="cart.{{ $productId }}.quantity"
                        aria-label="Quantity of {{ $item['name'] }}"
                        class="{{ $numberInput }} w-24"
                    />
                    <span class="text-xs text-ink-faint">{{ $unit->symbol() }}</span>
                </div>
            @else
                <div class="flex items-center rounded-full border border-line bg-surface shadow-soft">
                    <button
                        type="button"
                        x-on:click="$wire.$set('cart.{{ $productId }}.quantity', Math.max(1, Number($wire.$get('cart.{{ $productId }}.quantity')) - 1))"
                        @disabled($quantity <= 1)
                        aria-label="One less {{ $item['name'] }}"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-ink-muted hover:bg-surface-sunken hover:text-ink disabled:opacity-40"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M5 12h14" /></svg>
                    </button>
                    <input
                        type="number"
                        min="{{ $unit->inputMin() }}"
                        step="{{ $unit->inputStep() }}"
                        max="{{ $item['max_stock'] }}"
                        wire:model.live="cart.{{ $productId }}.quantity"
                        aria-label="Quantity of {{ $item['name'] }}"
                        class="w-10 border-0 bg-transparent p-0 text-center text-sm font-bold tabular-nums text-ink focus:ring-0 [appearance:textfield] [&::-webkit-inner-spin-button]:appearance-none [&::-webkit-outer-spin-button]:appearance-none"
                    />
                    <button
                        type="button"
                        x-on:click="$wire.$set('cart.{{ $productId }}.quantity', Math.min({{ (float) $item['max_stock'] }}, Number($wire.$get('cart.{{ $productId }}.quantity')) + 1))"
                        @disabled($quantity >= (float) $item['max_stock'])
                        aria-label="One more {{ $item['name'] }}"
                        class="flex h-8 w-8 items-center justify-center rounded-full text-ink-muted hover:bg-surface-sunken hover:text-ink disabled:opacity-40"
                    >
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
                    </button>
                </div>
            @endif
            @error("cart.$productId.quantity")
                <p class="max-w-48 whitespace-normal text-right text-xs text-danger">{{ $message }}</p>
            @enderror
        </div>

        <p class="ml-auto shrink-0 pt-1.5 text-right text-sm font-bold tabular-nums text-ink sm:ml-0 sm:w-28">Rs {{ number_format($lineTotal, 2) }}</p>

        <button type="button" wire:click="removeFromCart({{ $productId }})" aria-label="Remove {{ $item['name'] }}" class="mt-1 rounded-lg p-1 text-ink-faint hover:bg-danger-soft hover:text-danger">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <div x-show="adjust" x-cloak class="mt-3 grid max-w-sm grid-cols-2 gap-3">
        <label class="block text-xs font-semibold text-ink-muted">
            Price each
            <input
                type="number"
                min="0"
                step="0.01"
                placeholder="{{ number_format($item['list_price'], 2) }}"
                wire:model.live.debounce.300ms="cart.{{ $productId }}.custom_price"
                class="{{ $numberInput }} mt-1 block w-full"
            />
        </label>
        <label class="block text-xs font-semibold text-ink-muted">
            Discount (Rs)
            <input
                type="number"
                min="0"
                step="0.01"
                wire:model.live.debounce.300ms="cart.{{ $productId }}.discount"
                class="{{ $numberInput }} mt-1 block w-full"
            />
        </label>
        @error("cart.$productId.custom_price")
            <p class="col-span-2 text-xs text-danger">{{ $message }}</p>
        @enderror
    </div>
</li>
