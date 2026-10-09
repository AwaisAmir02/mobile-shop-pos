<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockIn;
use Illuminate\Support\Collection;

class RestoreStockFifo
{
    /**
     * Restores each item's quantity to the exact batch it was drawn from
     * (or directly to the product's stock_quantity for a legacy/unbatched
     * item), mirroring the batch it was originally consumed from rather
     * than re-running FIFO. Caller must already be inside a transaction.
     *
     * @param  Collection<int, SaleItem>  $items
     * @return array<int, string> product names that could not be restocked because the product no longer exists
     */
    public static function handle(Collection $items): array
    {
        $skipped = [];

        foreach ($items as $item) {
            if ($item->product_id === null) {
                $skipped[] = $item->product_name;

                continue;
            }

            if ($item->stock_in_id !== null) {
                $batch = StockIn::withoutGlobalScopes()->whereKey($item->stock_in_id)->lockForUpdate()->first();

                if ($batch === null) {
                    $skipped[] = $item->product_name;

                    continue;
                }

                $batch->increment('quantity_remaining', $item->quantity);
            }

            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

            if ($product === null) {
                $skipped[] = $item->product_name;

                continue;
            }

            $product->increment('stock_quantity', $item->quantity);
        }

        return $skipped;
    }
}
