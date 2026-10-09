<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\StockIn;

class ConsumeStockFifo
{
    /**
     * Locks and decrements the oldest available batches for $product, falling
     * back to the product's own price/cost for any quantity not covered by a
     * batch (a product with no stock-in history yet, or legacy stock that
     * predates batch tracking). Caller is expected to already hold a lock on
     * $product and to have validated $product->stock_quantity >= $quantity.
     *
     * @return array<int, array{stock_in_id: ?int, quantity: int, cost_price: ?float, selling_price: float}>
     */
    public static function handle(Product $product, int $quantity): array
    {
        $allocations = [];
        $remaining = $quantity;

        $batches = StockIn::availableBatchesFor($product->id)->lockForUpdate()->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $batch->quantity_remaining);
            $batch->decrement('quantity_remaining', $take);

            $allocations[] = self::allocationFromBatch($batch, $take, $product);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $allocations[] = self::fallbackAllocation($remaining, $product);
        }

        return $allocations;
    }

    /**
     * Same allocation logic as handle(), without locking or mutating any
     * row — used to preview a correct split-aware total before checkout.
     *
     * @return array<int, array{stock_in_id: ?int, quantity: int, cost_price: ?float, selling_price: float}>
     */
    public static function preview(Product $product, int $quantity): array
    {
        $allocations = [];
        $remaining = $quantity;

        $batches = StockIn::availableBatchesFor($product->id)->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, $batch->quantity_remaining);
            $allocations[] = self::allocationFromBatch($batch, $take, $product);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $allocations[] = self::fallbackAllocation($remaining, $product);
        }

        return $allocations;
    }

    /**
     * Same as preview(), but for an in-progress edit where this sale's own
     * existing items already hold some quantity against specific batches —
     * those batches are treated as if that quantity were already restored,
     * so the preview matches what UpdateSale will actually do (restore the
     * old consumption in full, then re-run FIFO fresh).
     *
     * @param  array<int, int>  $originalHoldingsByBatchId  stock_in_id => quantity this sale's own items currently hold against it
     * @return array<int, array{stock_in_id: ?int, quantity: int, cost_price: ?float, selling_price: float}>
     */
    public static function previewForEdit(Product $product, int $quantity, array $originalHoldingsByBatchId): array
    {
        $batchIds = array_keys($originalHoldingsByBatchId);

        $batches = StockIn::query()
            ->where('product_id', $product->id)
            ->where(fn ($query) => $query->where('quantity_remaining', '>', 0)->orWhereIn('id', $batchIds))
            ->orderBy('stock_date')
            ->orderBy('id')
            ->get();

        $allocations = [];
        $remaining = $quantity;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $virtualRemaining = $batch->quantity_remaining + ($originalHoldingsByBatchId[$batch->id] ?? 0);
            $take = min($remaining, $virtualRemaining);

            if ($take <= 0) {
                continue;
            }

            $allocations[] = self::allocationFromBatch($batch, $take, $product);
            $remaining -= $take;
        }

        if ($remaining > 0) {
            $allocations[] = self::fallbackAllocation($remaining, $product);
        }

        return $allocations;
    }

    private static function allocationFromBatch(StockIn $batch, int $quantity, Product $product): array
    {
        return [
            'stock_in_id' => $batch->id,
            'quantity' => $quantity,
            'cost_price' => $batch->cost_price !== null ? (float) $batch->cost_price : null,
            'selling_price' => $batch->selling_price !== null ? (float) $batch->selling_price : (float) $product->price,
        ];
    }

    private static function fallbackAllocation(int $quantity, Product $product): array
    {
        return [
            'stock_in_id' => null,
            'quantity' => $quantity,
            'cost_price' => $product->cost_price !== null ? (float) $product->cost_price : null,
            'selling_price' => (float) $product->price,
        ];
    }
}
