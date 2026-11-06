@props(['product' => null, 'limit' => null])

<x-ui.modal name="sale-quantity-prompt" max-width="sm" focusable>
    <form wire:submit="confirmQuantityPrompt" class="p-6">
        @if ($product)
            <h2 class="text-lg font-semibold text-slate-900">How much {{ $product->name }}?</h2>
            <p class="mt-1 text-sm text-slate-500">Rs {{ number_format($product->currentSellingPrice(), 2) }}{{ $product->unit->perSuffix() }} · {{ $product->unit->amount($limit) }} available</p>

            <div class="mt-5">
                <x-ui.field :label="'Quantity ('.$product->unit->symbol().')'" name="quantityPromptValue" for="quantityPromptValue">
                    <x-ui.input wire:model="quantityPromptValue" id="quantityPromptValue" type="number" :step="$product->unit->inputStep()" :min="$product->unit->inputMin()" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeQuantityPrompt">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="confirmQuantityPrompt">Add to Cart</x-ui.button>
            </div>
        @endif
    </form>
</x-ui.modal>
