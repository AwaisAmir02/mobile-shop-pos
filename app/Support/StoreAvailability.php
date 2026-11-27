<?php

namespace App\Support;

use App\Models\Product;
use App\Models\StoreConnection;
use Illuminate\Support\Collection;

/**
 * What a store may sell: what's free to sell (StockAvailability, the same
 * figure the chatbot, the register and sales orders use), less the
 * connection's safety buffer, in whole pieces, never below zero. Only ever
 * worked out, never stored, so nothing here touches stock or batches.
 */
class StoreAvailability
{
    /**
     * @param  Collection<int, Product>  $products  all from the connection's shop
     * @return array<int, int> keyed by product id
     */
    public static function for(StoreConnection $connection, Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $buffer = $connection->safety_buffer * 1000;

        return collect(StockAvailability::free($products))
            ->map(fn (int|float $free) => max(0, intdiv(Quantity::toThousandths($free) - $buffer, 1000)))
            ->all();
    }

    /**
     * A later figure must never be older than an earlier one, so take this before
     * reading the stock. Whichever figure carries the higher number is at least as new.
     */
    public static function nextSequence(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
