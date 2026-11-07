<?php

namespace App\Support;

use App\Models\Shop;
use App\Models\StoreConnection;
use App\Models\StoreEvent;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Queues what a store needs telling. Sending is StoreDelivery's job, so this can't fail the caller over a store being down. */
class StoreEvents
{
    /** How long to keep trying one event before stopping and telling the owner. */
    public const GIVE_UP_HOURS = 24;

    public static function record(StoreConnection $connection, string $type, array $data = []): ?StoreEvent
    {
        if (blank($connection->callback_url) || $connection->removed_at !== null) {
            return null;
        }

        return StoreEvent::create([
            'shop_id' => $connection->shop_id,
            'store_connection_id' => $connection->id,
            'public_id' => 'evt_'.Str::lower(Str::random(20)),
            'type' => $type,
            'data' => $data,
            'next_attempt_at' => now(),
            'give_up_at' => now()->addHours(self::GIVE_UP_HOURS),
        ]);
    }

    public static function forOrder(StoreOrder $order, string $type, array $data = []): void
    {
        $connection = StoreConnection::withoutGlobalScopes()->find($order->store_connection_id);

        if ($connection !== null) {
            self::record($connection, $type, ['external_order_id' => $order->external_order_id] + $data);
        }
    }

    public static function productUnpublished(StoreConnection $connection, StoreProduct $row): void
    {
        self::record($connection, 'product.unpublished', ['product_id' => $row->product_id, 'external_sku' => $row->external_sku]);
    }

    /**
     * A plan lapsing or coming back. A connection the owner paused themselves is
     * already paused and stays quiet until they resume it.
     */
    public static function featureSwitched(Shop $shop): void
    {
        $connections = StoreConnection::withoutGlobalScopes()
            ->where('shop_id', $shop->id)
            ->whereNull('removed_at')
            ->where('status', StoreConnection::ACTIVE)
            ->get();

        foreach ($connections as $connection) {
            self::record($connection, $shop->online_store_enabled ? 'connection.resumed' : 'connection.paused');

            if ($shop->online_store_enabled) {
                StoreChanges::current()->everything($connection);
            }
        }
    }

    /** The owner has fixed whatever was wrong: everything waiting gets a fresh day, and the store a full picture. */
    public static function resumeDelivery(StoreConnection $connection): void
    {
        DB::transaction(function () use ($connection) {
            StoreEvent::withoutGlobalScopes()
                ->where('store_connection_id', $connection->id)
                ->undelivered()
                ->update(['next_attempt_at' => now(), 'give_up_at' => now()->addHours(self::GIVE_UP_HOURS)]);

            $connection->update(['delivery_paused_at' => null, 'last_delivery_error' => null]);

            StoreChanges::current()->everything($connection);
        });
    }
}
