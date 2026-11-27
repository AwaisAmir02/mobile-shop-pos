<?php

use App\Actions\StoreConnections;
use App\Actions\StorefrontConnections;
use App\Enums\StoreDriver;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\StoreConnection;
use App\Storefronts\Shopify;
use App\Storefronts\StorefrontException;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * Shopify and WooCommerce stores (O9), beside the own-website API above it on
 * Settings › Integrations › Online Store. Connecting leaves for the platform's
 * own approval page and comes back to StorefrontConnectController.
 */
new class extends Component
{
    use Toasts;

    public string $shopifyDomain = '';

    public string $wooUrl = '';

    /** @var array<string, string> unmatched SKU => product id chosen for it */
    public array $mapTo = [];

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('settings-integrations') && StorefrontConnections::allowed(Auth::user()->shop), 403);
    }

    public function connectShopify(): void
    {
        $this->redirect(StorefrontConnections::startShopify(Auth::user()->shop, $this->shopifyDomain, Auth::id()));
    }

    public function connectWooCommerce(): void
    {
        $this->redirect(StorefrontConnections::startWooCommerce(Auth::user()->shop, $this->wooUrl, Auth::id()));
    }

    protected function connection(int $id): StoreConnection
    {
        return StoreConnection::notRemoved()->whereIn('driver', [StoreDriver::Shopify->value, StoreDriver::WooCommerce->value])->findOrFail($id);
    }

    public function importCatalog(int $id): void
    {
        try {
            $result = StorefrontConnections::importCatalog($this->connection($id));
        } catch (StorefrontException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess($result['matched'].' products matched'.($result['unmatched'] !== [] ? ', '.count($result['unmatched']).' still to map.' : '.'));
    }

    /** A store SKU that matched nothing, mapped to a product here by hand; its ids come with the next import. */
    public function map(int $id, string $sku): void
    {
        $connection = $this->connection($id);
        $product = Product::whereNull('archived_at')->findOrFail((int) ($this->mapTo[$sku] ?? 0));

        StoreConnections::publish($connection, collect([$product]));
        StoreConnections::setExternalSku($connection, $product, $sku);
        unset($this->mapTo[$sku]);

        $this->importCatalog($id);
    }

    public function disconnect(int $id): void
    {
        StoreConnections::remove($this->connection($id));
        $this->toastSuccess('Disconnected. The store keeps its products; nothing more is sent to it.');
    }

    public function with(): array
    {
        return [
            'stores' => StoreConnection::notRemoved()->whereIn('driver', [StoreDriver::Shopify->value, StoreDriver::WooCommerce->value])->orderBy('id')->get(),
            'shopifyReady' => Shopify::configured(),
            'products' => Product::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div class="space-y-6" data-storefronts>
    @if (session('storefront_connected'))
        <x-ui.alert variant="success">{{ session('storefront_connected') }}</x-ui.alert>
    @endif
    @if (session('storefront_error'))
        <x-ui.alert variant="danger">{{ session('storefront_error') }}</x-ui.alert>
    @endif

    <x-ui.card title="Shopify or WooCommerce store" description="Already sell on Shopify or a WooCommerce (WordPress) site? Connect it: its orders arrive in Online Orders, and it's told your live stock, so the same piece can't be sold twice.">
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <form wire:submit="connectShopify" class="space-y-3">
                <x-ui.field label="Shopify address" name="shopifyDomain" for="shopifyDomain" help="Like mystore.myshopify.com">
                    <x-ui.input wire:model="shopifyDomain" id="shopifyDomain" :disabled="! $shopifyReady" />
                </x-ui.field>
                <x-ui.button type="submit" :disabled="! $shopifyReady">Connect Shopify</x-ui.button>
                @unless ($shopifyReady)
                    <p class="text-xs text-ink-faint">Shopify isn't switched on for HisabOKitab yet.</p>
                @endunless
            </form>
            <form wire:submit="connectWooCommerce" class="space-y-3">
                <x-ui.field label="WooCommerce store address" name="wooUrl" for="wooUrl" help="Your site's https:// address. You'll approve access on your own site.">
                    <x-ui.input wire:model="wooUrl" id="wooUrl" placeholder="https://mystore.pk" />
                </x-ui.field>
                <x-ui.button type="submit">Connect WooCommerce</x-ui.button>
            </form>
        </div>
    </x-ui.card>

    @foreach ($stores as $store)
        @php($unmatched = $store->setting('unmatched', []))
        <x-ui.card :title="$store->driver->label().' · '.$store->external_domain" wire:key="store-{{ $store->id }}" data-storefront="{{ $store->id }}">
            <div class="space-y-4 text-sm">
                <p class="text-ink-muted">
                    {{ $store->connected_at ? 'Connected '.$store->connected_at->format('d M Y') : 'Not finished: approve access on the store again.' }}
                    · {{ $store->products()->where('published', true)->count() }} products mapped
                    @if ($store->last_delivery_error)
                        · <span class="text-danger">{{ $store->last_delivery_error }}</span>
                    @endif
                </p>
                <div class="flex flex-wrap gap-2">
                    <x-ui.button size="sm" variant="secondary" wire:click="importCatalog({{ $store->id }})" wire:loading.attr="disabled">Import products again</x-ui.button>
                    <x-ui.button size="sm" variant="danger-ghost" wire:click="disconnect({{ $store->id }})" wire:confirm="Disconnect {{ $store->external_domain }}? Orders already here stay.">Disconnect</x-ui.button>
                </div>

                @if ($unmatched !== [])
                    <div class="space-y-2" data-unmatched>
                        <p class="font-medium text-ink">Products on the store with no match here</p>
                        <p class="text-xs text-ink-faint">Matched by SKU to a product's barcode here. Pick the product for each, or give it that barcode.</p>
                        @foreach ($unmatched as $item)
                            <div class="flex flex-wrap items-end gap-2" wire:key="unmatched-{{ $store->id }}-{{ $item['sku'] }}">
                                <div class="min-w-0 flex-1">
                                    <span class="block truncate text-ink">{{ $item['name'] }}</span>
                                    <span class="text-xs text-ink-faint">{{ $item['sku'] }}</span>
                                </div>
                                <x-ui.select wire:model="mapTo.{{ $item['sku'] }}" aria-label="Product for {{ $item['sku'] }}">
                                    <option value="">Choose…</option>
                                    @foreach ($products as $product)
                                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                                    @endforeach
                                </x-ui.select>
                                <x-ui.button size="sm" variant="secondary" wire:click="map({{ $store->id }}, @js($item['sku']))">Map</x-ui.button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-ui.card>
    @endforeach
</div>
