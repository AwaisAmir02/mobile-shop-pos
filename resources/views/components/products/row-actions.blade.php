@props(['product', 'barcodeEnabled' => false, 'whatsappEnabled' => false])

<div @class(['flex gap-2', 'justify-end' => ! $product->isVariant(), 'w-72 flex-wrap justify-start' => $product->isVariant()])>
    @if ($barcodeEnabled && $product->type !== 'sim')
        <x-ui.button size="sm" variant="ghost" wire:click="openPrintLabel({{ $product->id }})">
            Print Label
        </x-ui.button>
    @endif
    @if ($whatsappEnabled && $product->type !== 'sim' && ! $product->unit->allowsDecimals())
        <x-ui.button size="sm" variant="ghost" wire:click="toggleWhatsAppListing({{ $product->id }})" @class(['text-emerald-700' => $product->whatsapp_listed]) title="{{ $product->whatsapp_listed ? 'Listed on WhatsApp — click to remove' : 'Not on WhatsApp — click to list' }}">
            {{ $product->whatsapp_listed ? 'On WhatsApp' : 'Add to WhatsApp' }}
        </x-ui.button>
    @endif
    @if ($product->type !== 'sim')
        <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $product->id }})">
            Edit
        </x-ui.button>
    @endif
    @if ($product->isVariant())
        <x-ui.button size="sm" variant="ghost" wire:click="removeFromGroup({{ $product->id }})" wire:confirm="Take {{ $product->name }} out of its group? It stays as a normal product with its stock and barcode.">
            Remove from group
        </x-ui.button>
    @endif
    @if ($product->isArchived())
        <x-ui.button size="sm" variant="ghost" wire:click="restore({{ $product->id }})">Restore</x-ui.button>
    @elseif ($product->canBeArchived())
        <x-ui.button size="sm" variant="ghost" wire:click="archive({{ $product->id }})" title="{{ $product->isVariant() ? 'Hide this out-of-stock variant' : 'Hide this out-of-stock product so it no longer counts towards your plan' }}">Archive</x-ui.button>
    @endif
    <x-ui.button
        size="sm"
        variant="ghost"
        wire:click="delete({{ $product->id }})"
        wire:confirm="Delete {{ $product->name }}? This cannot be undone."
        class="text-red-600 hover:bg-red-50"
    >
        Delete
    </x-ui.button>
</div>
