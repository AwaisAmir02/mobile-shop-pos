@props(['product'])

@if ($product->isArchived())
    <x-ui.badge>Archived</x-ui.badge>
@elseif ($product->isOutOfStock())
    <x-ui.badge variant="danger">Out of stock</x-ui.badge>
@elseif ($product->isLowStock())
    <x-ui.badge variant="warning">Low · {{ $product->stockLabel() }}</x-ui.badge>
@else
    <x-ui.badge variant="success">{{ $product->stockLabel() }} in stock</x-ui.badge>
@endif
