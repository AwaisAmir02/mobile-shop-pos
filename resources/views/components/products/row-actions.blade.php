@props(['product', 'barcodeEnabled' => false, 'whatsappEnabled' => false])

{{-- A product row's ⋯ menu (x-ui.row-actions). Same actions and conditions as
     before; Delete and "Remove from group" keep their confirmation. --}}
<x-ui.row-actions label="Actions for {{ $product->name }}">
    @if ($product->type !== 'sim')
        <x-ui.menu-item wire:click="openEdit({{ $product->id }})">Edit</x-ui.menu-item>
    @endif
    @if ($barcodeEnabled && $product->type !== 'sim')
        <x-ui.menu-item wire:click="openPrintLabel({{ $product->id }})">Print Label</x-ui.menu-item>
    @endif
    @if ($whatsappEnabled && $product->type !== 'sim' && ! $product->unit->allowsDecimals())
        <x-ui.menu-item wire:click="toggleWhatsAppListing({{ $product->id }})" title="{{ $product->whatsapp_listed ? 'Listed on WhatsApp — click to remove' : 'Not on WhatsApp — click to list' }}">
            {{ $product->whatsapp_listed ? 'On WhatsApp' : 'Add to WhatsApp' }}
        </x-ui.menu-item>
    @endif
    @if ($product->isVariant())
        <x-ui.menu-item
            wire:click="removeFromGroup({{ $product->id }})"
            confirm="Take {{ $product->name }} out of its group? It stays as a normal product with its stock and barcode."
            confirm-title="Remove from group?"
            confirm-label="Remove"
        >Remove from group</x-ui.menu-item>
    @endif
    @if ($product->isArchived())
        <x-ui.menu-item wire:click="restore({{ $product->id }})">Restore</x-ui.menu-item>
    @elseif ($product->canBeArchived())
        <x-ui.menu-item wire:click="archive({{ $product->id }})" title="{{ $product->isVariant() ? 'Hide this out-of-stock variant' : 'Hide this out-of-stock product so it no longer counts towards your plan' }}">Archive</x-ui.menu-item>
    @endif
    <x-ui.menu-divider />
    <x-ui.menu-item
        danger
        wire:click="delete({{ $product->id }})"
        confirm="Delete {{ $product->name }}? This cannot be undone."
        confirm-title="Delete this product?"
        confirm-label="Delete"
    >Delete</x-ui.menu-item>
</x-ui.row-actions>
