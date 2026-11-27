<?php

namespace App\Actions;

use App\Enums\OnlineTool;
use App\Enums\StoreDriver;
use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use App\Storefronts\Shopify;
use App\Storefronts\StorefrontException;
use App\Storefronts\Storefronts;
use App\Storefronts\WooCommerce;
use App\Support\StoreCallbackGuard;
use App\Support\StoreChanges;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting a Shopify or WooCommerce store (O9), and mapping its catalogue to
 * the shop's products. The connection is an ordinary store connection with a
 * platform driver: publishing, stock (StoreAvailability), the outbox and the
 * order inbox are the ones the own-website API already uses.
 *
 * Both connect flows leave the shop and come back. A one-time state, kept for a
 * short while, ties the return to the shop that started it; nothing a stranger
 * sends can attach a store to a shop.
 */
class StorefrontConnections
{
    public const STATE_MINUTES = 30;

    // ── Shopify ──────────────────────────────────────────────────────────

    public static function startShopify(Shop $shop, string $domainInput, int $userId): string
    {
        self::assertAllowed($shop);

        if (! Shopify::configured()) {
            throw ValidationException::withMessages(['shopifyDomain' => 'Shopify isn\'t set up on our side yet. Ask HisabOKitab support.']);
        }

        $domain = Shopify::shopDomain($domainInput) ?? throw ValidationException::withMessages(['shopifyDomain' => 'Enter your Shopify address, like mystore.myshopify.com.']);
        $state = self::remember(StoreDriver::Shopify, ['shop_id' => $shop->id, 'domain' => $domain, 'user_id' => $userId]);

        return app(Shopify::class)->installUrl($domain, $state);
    }

    /**
     * Shopify's redirect back. Returns the connection, set up and catalogue imported.
     *
     * @param  array<string, mixed>  $query
     */
    public static function completeShopify(array $query, Shop $shop): StoreConnection
    {
        $shopify = app(Shopify::class);
        $pending = self::recall(StoreDriver::Shopify, (string) ($query['state'] ?? ''));

        if (! $shopify->verifyCallback($query) || $pending === null || (int) $pending['shop_id'] !== $shop->id || $pending['domain'] !== Shopify::shopDomain((string) ($query['shop'] ?? ''))) {
            throw new StorefrontException('That Shopify approval couldn\'t be checked. Start again from Settings.');
        }

        $token = $shopify->exchangeCode($pending['domain'], (string) ($query['code'] ?? ''));

        $connection = self::platformConnection($shop, StoreDriver::Shopify, $pending['domain'], $pending['user_id'], [
            'access_token' => $token['access_token'], 'scope' => $token['scope'],
        ]);

        $connection->update(['settings' => array_merge($connection->settings ?? [], ['location_id' => $shopify->primaryLocation($connection)])]);
        $shopify->registerWebhooks($connection);
        $connection->update(['connected_at' => now(), 'status' => StoreConnection::ACTIVE]);
        self::importCatalog($connection->fresh());

        return $connection->fresh();
    }

    // ── WooCommerce ──────────────────────────────────────────────────────

    public static function startWooCommerce(Shop $shop, string $urlInput, int $userId): string
    {
        self::assertAllowed($shop);

        $url = WooCommerce::storeUrl($urlInput) ?? throw ValidationException::withMessages(['wooUrl' => 'Enter your store\'s https:// address.']);

        if (($problem = app(StoreCallbackGuard::class)->problemWith($url)) !== null) {
            throw ValidationException::withMessages(['wooUrl' => $problem]);
        }

        $state = self::remember(StoreDriver::WooCommerce, ['shop_id' => $shop->id, 'url' => $url, 'user_id' => $userId]);

        return app(WooCommerce::class)->authorizeUrl($url, $state);
    }

    /**
     * The keys WooCommerce posts to our callback once the owner approves. Answered
     * at once: calling the store back from inside this request isn't allowed.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function receiveWooKeys(array $payload): ?StoreConnection
    {
        $state = (string) ($payload['user_id'] ?? '');
        $pending = self::recall(StoreDriver::WooCommerce, $state, forget: false);

        if ($pending === null || blank($payload['consumer_key'] ?? null) || blank($payload['consumer_secret'] ?? null)) {
            return null;
        }

        if (($payload['key_permissions'] ?? 'read_write') !== 'read_write') {
            return null;
        }

        $shop = Shop::findOrFail($pending['shop_id']);
        $connection = self::platformConnection($shop, StoreDriver::WooCommerce, $pending['url'], $pending['user_id'], [
            'consumer_key' => (string) $payload['consumer_key'], 'consumer_secret' => (string) $payload['consumer_secret'], 'key_id' => (string) ($payload['key_id'] ?? ''),
        ]);

        Cache::put(self::cacheKey(StoreDriver::WooCommerce, $state), $pending + ['connection_id' => $connection->id], now()->addMinutes(self::STATE_MINUTES));

        return $connection;
    }

    /** The owner is back from WooCommerce: register our webhooks and import the catalogue. */
    public static function finishWooCommerce(string $state, Shop $shop): StoreConnection
    {
        $pending = self::recall(StoreDriver::WooCommerce, $state);

        if ($pending === null || (int) $pending['shop_id'] !== $shop->id || empty($pending['connection_id'])) {
            throw new StorefrontException('WooCommerce didn\'t send the keys. Make sure you pressed Approve, and try again.');
        }

        $connection = StoreConnection::where('shop_id', $shop->id)->findOrFail($pending['connection_id']);
        app(WooCommerce::class)->registerWebhooks($connection);
        $connection->update(['connected_at' => now(), 'status' => StoreConnection::ACTIVE]);
        self::importCatalog($connection->fresh());

        return $connection->fresh();
    }

    // ── Catalogue ────────────────────────────────────────────────────────

    /**
     * Reads the store's products and maps each to one of the shop's by SKU: an
     * earlier mapping on this connection, else a product whose barcode is that SKU.
     * Mapped ones are published, so their stock is sent at once. Unmatched SKUs
     * are kept on the connection to map by hand (StoreConnections::setExternalSku).
     *
     * @return array{matched: int, unmatched: list<array{sku: string, name: string}>}
     */
    public static function importCatalog(StoreConnection $connection): array
    {
        $platform = app(Storefronts::class)->platform($connection->driver);
        $catalog = $platform->catalog($connection);
        $key = fn (array $item) => $connection->driver === StoreDriver::Shopify ? Shopify::catalogSku($item) : WooCommerce::catalogSku($item);

        return DB::transaction(function () use ($connection, $catalog, $key) {
            $mapped = StoreProduct::where('store_connection_id', $connection->id)->whereNotNull('external_sku')->get()->keyBy('external_sku');
            $byBarcode = Product::where('shop_id', $connection->shop_id)->whereNotNull('barcode')->whereNull('archived_at')
                ->whereIn('barcode', array_filter(array_column($catalog, 'sku')))->pluck('id', 'barcode');
            $matched = 0;
            $unmatched = [];

            foreach ($catalog as $item) {
                $sku = $key($item);
                $row = $mapped[$sku] ?? null;
                $productId = $row?->product_id ?? (isset($item['sku']) ? ($byBarcode[$item['sku']] ?? null) : null);

                if ($productId === null) {
                    $unmatched[] = ['sku' => $sku, 'name' => mb_substr($item['name'], 0, 120)];

                    continue;
                }

                StoreProduct::updateOrCreate(
                    ['store_connection_id' => $connection->id, 'product_id' => $productId],
                    ['shop_id' => $connection->shop_id, 'external_sku' => $sku, 'external_ref' => $item['external_ref'], 'inventory_ref' => $item['inventory_ref'], 'published' => true],
                );
                $matched++;
            }

            $connection->update(['settings' => array_merge($connection->settings ?? [], ['unmatched' => array_slice($unmatched, 0, 200), 'catalog_imported_at' => now()->toIso8601String()])]);
            StoreChanges::current()->everything($connection);

            return ['matched' => $matched, 'unmatched' => $unmatched];
        });
    }

    /** The platform took the app away (Shopify app/uninstalled, shop/redact): our keys are useless, so forget them. */
    public static function disconnected(StoreConnection $connection): void
    {
        $connection->forceFill(['credentials' => null, 'connected_at' => null, 'removed_at' => $connection->removed_at ?? now(), 'status' => StoreConnection::PAUSED])->save();
    }

    // ── Shared ───────────────────────────────────────────────────────────

    public static function allowed(Shop $shop): bool
    {
        return (bool) $shop->online_store_enabled && $shop->usesTool(OnlineTool::Storefronts);
    }

    protected static function assertAllowed(Shop $shop): void
    {
        if (! self::allowed($shop)) {
            throw ValidationException::withMessages(['store' => 'Store connections aren\'t available on this shop\'s plan.']);
        }
    }

    /** @param  array<string, string>  $credentials */
    protected static function platformConnection(Shop $shop, StoreDriver $driver, string $domain, ?int $userId, array $credentials): StoreConnection
    {
        $connection = StoreConnection::withoutGlobalScopes()->where('shop_id', $shop->id)->where('driver', $driver->value)->where('external_domain', $domain)->first()
            ?? new StoreConnection(['shop_id' => $shop->id, 'public_id' => 'chn_'.Str::lower(Str::random(16)), 'driver' => $driver, 'external_domain' => $domain, 'name' => $domain, 'created_by' => $userId]);

        $connection->fill(['credentials' => $credentials, 'removed_at' => null, 'status' => StoreConnection::ACTIVE]);
        $connection->signing_secret ??= 'whsec_'.Str::random(40);
        $connection->shop_id = $shop->id;
        $connection->save();

        return $connection;
    }

    protected static function cacheKey(StoreDriver $driver, string $state): string
    {
        return 'storefronts:'.$driver->value.':'.$state;
    }

    protected static function remember(StoreDriver $driver, array $data): string
    {
        $state = Str::random(40);
        Cache::put(self::cacheKey($driver, $state), $data, now()->addMinutes(self::STATE_MINUTES));

        return $state;
    }

    protected static function recall(StoreDriver $driver, string $state, bool $forget = true): ?array
    {
        if (strlen($state) !== 40 || ! ctype_alnum($state)) {
            return null;
        }

        $data = $forget ? Cache::pull(self::cacheKey($driver, $state)) : Cache::get(self::cacheKey($driver, $state));

        return is_array($data) ? $data : null;
    }
}
