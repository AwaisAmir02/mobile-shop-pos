<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StoreApiToken;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Everything an owner does to a connection. Plain token and secret are returned once and never stored. */
class StoreConnections
{
    /** How long the old signing secret keeps working after a new one is made. */
    public const SECRET_OVERLAP_HOURS = 24;

    /** @return array{connection: StoreConnection, token: string, secret: string} */
    public static function create(int $shopId, string $name, ?int $userId): array
    {
        self::assertEnabled($shopId);

        return DB::transaction(function () use ($shopId, $name, $userId) {
            $secret = self::newSecret();

            $connection = StoreConnection::create([
                'shop_id' => $shopId,
                'public_id' => 'chn_'.Str::lower(Str::random(16)),
                'name' => trim($name),
                'signing_secret' => $secret,
                'created_by' => $userId,
            ]);

            return ['connection' => $connection, 'token' => self::issueToken($connection), 'secret' => $secret];
        });
    }

    /** Replaces the connection's token: the old one stops working at once. */
    public static function issueToken(StoreConnection $connection): string
    {
        self::assertNotRemoved($connection);

        return DB::transaction(function () use ($connection) {
            $connection->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

            $plain = 'hok_'.Str::random(40);

            $connection->tokens()->create([
                'token_hash' => StoreApiToken::hash($plain),
                'token_prefix' => substr($plain, 0, 10),
                'scopes' => StoreApiToken::SCOPES,
            ]);

            return $plain;
        });
    }

    /** The old secret stays valid for a while, so the store can switch without losing orders. */
    public static function rotateSecret(StoreConnection $connection): string
    {
        self::assertNotRemoved($connection);

        $secret = self::newSecret();

        $connection->update([
            'previous_signing_secret' => $connection->signing_secret,
            'previous_secret_expires_at' => now()->addHours(self::SECRET_OVERLAP_HOURS),
            'signing_secret' => $secret,
        ]);

        return $secret;
    }

    public static function pause(StoreConnection $connection): void
    {
        self::assertNotRemoved($connection);
        $connection->update(['status' => StoreConnection::PAUSED]);
    }

    public static function resume(StoreConnection $connection): void
    {
        self::assertNotRemoved($connection);
        self::assertEnabled($connection->shop_id);
        $connection->update(['status' => StoreConnection::ACTIVE]);
    }

    /** Ends it for good. The row stays because later orders will point at it. */
    public static function remove(StoreConnection $connection): void
    {
        DB::transaction(function () use ($connection) {
            $connection->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $connection->update(['status' => StoreConnection::PAUSED, 'removed_at' => now()]);
        });
    }

    /** $shopAccountId is where this store's prepaid money lands, suggested when accepting an order. */
    public static function updateSettings(StoreConnection $connection, bool $priceSync, int $safetyBuffer, ?int $shopAccountId = null): void
    {
        self::assertNotRemoved($connection);
        $connection->update(['price_sync' => $priceSync, 'safety_buffer' => $safetyBuffer, 'shop_account_id' => $shopAccountId]);
    }

    /** @param  Collection<int, Product>  $products  */
    public static function publish(StoreConnection $connection, Collection $products): void
    {
        self::assertNotRemoved($connection);

        foreach ($products as $product) {
            self::assertSameShop($connection, $product);

            if (($reason = $product->blocksOnlinePublishing()) !== null) {
                throw ValidationException::withMessages(['product' => "{$product->name} can't be published: {$reason}."]);
            }

            StoreProduct::updateOrCreate(
                ['store_connection_id' => $connection->id, 'product_id' => $product->id],
                ['shop_id' => $connection->shop_id, 'published' => true],
            );
        }
    }

    public static function unpublish(StoreConnection $connection, Product $product): void
    {
        self::assertSameShop($connection, $product);

        StoreProduct::where('store_connection_id', $connection->id)
            ->where('product_id', $product->id)
            ->first()
            ?->update(['published' => false]);
    }

    /** The store's own identifier, unique on the connection. Blank clears it. */
    public static function setExternalSku(StoreConnection $connection, Product $product, ?string $sku): void
    {
        self::assertNotRemoved($connection);
        self::assertSameShop($connection, $product);

        $sku = filled($sku) ? trim($sku) : null;

        if ($sku !== null && StoreProduct::where('store_connection_id', $connection->id)->where('external_sku', $sku)->where('product_id', '!=', $product->id)->exists()) {
            throw ValidationException::withMessages(['sku' => "Another product on this connection already uses \"{$sku}\"."]);
        }

        $row = StoreProduct::firstOrNew(
            ['store_connection_id' => $connection->id, 'product_id' => $product->id],
            ['shop_id' => $connection->shop_id, 'published' => false],
        );
        $row->external_sku = $sku;
        $row->save();
    }

    protected static function newSecret(): string
    {
        return 'hoksec_'.Str::random(48);
    }

    protected static function assertEnabled(int $shopId): void
    {
        if (! Shop::whereKey($shopId)->value('online_store_enabled')) {
            throw ValidationException::withMessages(['connection' => 'Online store isn\'t enabled for this shop.']);
        }
    }

    protected static function assertNotRemoved(StoreConnection $connection): void
    {
        if ($connection->removed_at !== null) {
            throw ValidationException::withMessages(['connection' => 'This connection has been removed.']);
        }
    }

    protected static function assertSameShop(StoreConnection $connection, Product $product): void
    {
        if ((int) $product->shop_id !== (int) $connection->shop_id) {
            throw ValidationException::withMessages(['product' => 'That product isn\'t in this shop.']);
        }
    }
}
