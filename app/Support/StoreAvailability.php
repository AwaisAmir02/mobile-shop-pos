<?php

namespace App\Support;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StoreConnection;
use App\Models\WhatsAppOrderItem;
use App\Enums\WhatsAppOrderStatus;
use Illuminate\Support\Collection;

/**
 * What a store may sell: sellable stock, less what's held for orders still waiting
 * for a decision, less the shop's safety buffer, never below zero. Only ever worked
 * out, never stored, so nothing here touches stock or batches.
 */
class StoreAvailability
{
    /**
     * Product::sellableStock() for many products at once.
     *
     * @param  Collection<int, Product>  $products  all from the connection's shop
     * @return array<int, int> keyed by product id
     */
    public static function for(StoreConnection $connection, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $ids = $products->pluck('id');
        $expired = self::expiredThousandths($connection->shop_id, $ids);
        $pending = self::pendingWhatsAppThousandths($ids);
        $buffer = $connection->safety_buffer * 1000;

        return $products->mapWithKeys(function (Product $product) use ($expired, $pending, $buffer) {
            $left = Quantity::toThousandths($product->stock_quantity)
                - ($expired[$product->id] ?? 0)
                - ($pending[$product->id] ?? 0)
                - $buffer;

            return [$product->id => $left > 0 ? intdiv($left, 1000) : 0];
        })->all();
    }

    /**
     * A later figure must never be older than an earlier one, so take this before
     * reading the stock. Whichever figure carries the higher number is at least as new.
     */
    public static function nextSequence(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** @return array<int, int> */
    protected static function expiredThousandths(int $shopId, Collection $ids): array
    {
        if (! Shop::whereKey($shopId)->value('expiry_tracking_enabled')) {
            return [];
        }

        return StockIn::query()
            ->whereIn('product_id', $ids)
            ->where('quantity_remaining', '>', 0)
            ->where('expiry_date', '<', BatchExpiry::today())
            ->selectRaw('product_id, SUM(quantity_remaining) as total')
            ->groupBy('product_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => Quantity::toThousandths(Quantity::normalize($row->total))])
            ->all();
    }

    /** @return array<int, int> */
    protected static function pendingWhatsAppThousandths(Collection $ids): array
    {
        return WhatsAppOrderItem::query()
            ->whereIn('product_id', $ids)
            ->whereHas('order', fn ($query) => $query->where('status', WhatsAppOrderStatus::Pending->value))
            ->selectRaw('product_id, SUM(quantity) as total')
            ->groupBy('product_id')
            ->toBase()
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->product_id => Quantity::toThousandths(Quantity::normalize($row->total))])
            ->all();
    }
}
