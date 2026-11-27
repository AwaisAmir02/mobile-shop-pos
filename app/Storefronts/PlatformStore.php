<?php

namespace App\Storefronts;

use App\Models\StoreConnection;
use App\Models\StoreEvent;
use App\Models\StoreProduct;

/**
 * A store platform the shop connects (Shopify, WooCommerce). The platform is
 * only ever told how many it may sell; it never changes stock here. Orders it
 * takes come back as webhooks into the same inbox as our own API's
 * (StoreOrders::receiveMapped), and become sales only when the shop accepts.
 *
 * The figure sent is StoreAvailability's (StockAvailability less the
 * connection's buffer), the one the chatbot, register and API also use.
 */
abstract class PlatformStore
{
    /**
     * Every product on the store that we could map: its own ids, its SKU and its name.
     *
     * @return list<array{external_ref: string, inventory_ref: ?string, sku: ?string, name: string}>
     */
    abstract public function catalog(StoreConnection $connection): array;

    /**
     * Sets the quantity on sale for mapped products.
     *
     * @param  list<array{row: StoreProduct, available: int}>  $items
     *
     * @throws StorefrontException
     */
    abstract public function setStock(StoreConnection $connection, array $items): void;

    /** Asks the platform to send us its order webhooks. @throws StorefrontException */
    abstract public function registerWebhooks(StoreConnection $connection): void;

    /**
     * One outbox event (StoreDelivery). Only stock is sent to a platform; product
     * and order events have nothing to tell it, so they count as delivered.
     *
     * @return array{0: ?int, 1: ?string} the status, and what went wrong if anything did
     */
    public function deliver(StoreConnection $connection, StoreEvent $event): array
    {
        if ($event->type !== 'inventory.updated') {
            return [200, null];
        }

        $available = collect($event->data['items'] ?? [])->mapWithKeys(fn (array $item) => [(int) $item['product_id'] => (int) $item['available']]);

        $items = StoreProduct::withoutGlobalScopes()
            ->where('store_connection_id', $connection->id)
            ->whereIn('product_id', $available->keys())
            ->whereNotNull('external_ref')
            ->get()
            ->map(fn (StoreProduct $row) => ['row' => $row, 'available' => $available[$row->product_id]])
            ->values()
            ->all();

        if ($items === []) {
            return [200, null];
        }

        try {
            $this->setStock($connection, $items);
        } catch (StorefrontException $e) {
            return [null, mb_substr($e->getMessage(), 0, 255)];
        }

        return [200, null];
    }
}
