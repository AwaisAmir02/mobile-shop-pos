<?php

namespace App\Support;

use App\Models\StoreConnection;
use App\Models\StoreProduct;
use Illuminate\Support\Facades\DB;

/**
 * Notes what a store needs telling, in the same transaction as the change, so it
 * can't be lost. It only inserts a marker: working out figures and calling the store
 * happen later, so this can't slow or fail a sale. A shop with no store to tell does
 * one remembered check and no writes. Bound scoped, and forgotten when a connection saves.
 */
class StoreChanges
{
    public const STOCK = 'stock';

    public const CATALOG = 'catalog';

    /** @var array<int, array<int, bool>> shop id => connection id => whether it syncs prices */
    protected array $connections = [];

    public static function current(): self
    {
        return app(self::class);
    }

    public function forget(): void
    {
        $this->connections = [];
    }

    /** Stock moved, so availability did. With price sync on the next batch's price may have too. */
    public function stockChanged(int $shopId, int $productId): void
    {
        $connections = $this->connectionsFor($shopId);

        if ($connections === []) {
            return;
        }

        foreach ($this->connectionsCarrying(array_keys($connections), $productId) as $connectionId) {
            $this->mark($connectionId, $productId, self::STOCK);

            if ($connections[$connectionId]) {
                $this->mark($connectionId, $productId, self::CATALOG);
            }
        }
    }

    public function catalogChanged(int $shopId, int $productId): void
    {
        $connections = $this->connectionsFor($shopId);

        if ($connections === []) {
            return;
        }

        foreach ($this->connectionsCarrying(array_keys($connections), $productId) as $connectionId) {
            $this->mark($connectionId, $productId, self::CATALOG);
            $this->mark($connectionId, $productId, self::STOCK);
        }
    }

    /** Publishing or unpublishing: this connection only, whatever the row now says. */
    public function publicationChanged(StoreProduct $row): void
    {
        if (isset($this->connectionsFor((int) $row->shop_id)[$row->store_connection_id])) {
            $this->mark($row->store_connection_id, $row->product_id, self::CATALOG);
            $this->mark($row->store_connection_id, $row->product_id, self::STOCK);
        }
    }

    /** Everything published: a store's first push, a resume, and the nightly heal. */
    public function everything(StoreConnection $connection): void
    {
        StoreProduct::withoutGlobalScopes()
            ->where('store_connection_id', $connection->id)
            ->where('published', true)
            ->select('product_id')
            ->chunkById(500, function ($rows) use ($connection) {
                DB::table('store_changes')->insertOrIgnore($rows->map(fn ($row) => [
                    'store_connection_id' => $connection->id,
                    'product_id' => $row->product_id,
                    'kind' => self::STOCK,
                    'created_at' => now(),
                ])->all());
            }, 'product_id');
    }

    /** @return array<int, bool> */
    protected function connectionsFor(int $shopId): array
    {
        return $this->connections[$shopId] ??= StoreConnection::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->whereNull('removed_at')
            ->whereNotNull('callback_url')
            ->pluck('price_sync', 'id')
            ->map(fn ($sync) => (bool) $sync)
            ->all();
    }

    /** @return array<int, int> */
    protected function connectionsCarrying(array $connectionIds, int $productId): array
    {
        return StoreProduct::withoutGlobalScopes()
            ->whereIn('store_connection_id', $connectionIds)
            ->where('product_id', $productId)
            ->where('published', true)
            ->pluck('store_connection_id')
            ->all();
    }

    protected function mark(int $connectionId, int $productId, string $kind): void
    {
        DB::table('store_changes')->insertOrIgnore([
            'store_connection_id' => $connectionId,
            'product_id' => $productId,
            'kind' => $kind,
            'created_at' => now(),
        ]);
    }
}
