<?php

namespace App\Actions;

use App\Enums\SaleRevisionAction;
use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRevision;
use Illuminate\Support\Facades\DB;

class CancelSale
{
    /**
     * @return array{restocked: array<int, string>, skipped: array<int, string>}
     */
    public static function handle(Sale $sale, ?int $userId, ?string $reason = null): array
    {
        return DB::transaction(function () use ($sale, $userId, $reason) {
            $sale = Sale::withoutGlobalScopes()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('This sale has already been cancelled.');
            }

            $sale->loadMissing(['items', 'payments']);

            $snapshot = [
                'subtotal' => (float) $sale->subtotal,
                'discount_amount' => (float) $sale->discount_amount,
                'total' => (float) $sale->total,
                'customer_id' => $sale->customer_id,
                'items' => $sale->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'unit_price' => (float) $item->unit_price,
                    'list_price' => $item->list_price !== null ? (float) $item->list_price : null,
                    'quantity' => (int) $item->quantity,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_total' => (float) $item->line_total,
                ])->all(),
            ];

            $restocked = [];
            $skipped = [];

            foreach ($sale->items as $item) {
                if ($item->product_id === null) {
                    $skipped[] = $item->product_name;

                    continue;
                }

                $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

                if ($product === null) {
                    $skipped[] = $item->product_name;

                    continue;
                }

                $product->increment('stock_quantity', $item->quantity);
                $restocked[] = $item->product_name;
            }

            $sale->update([
                'status' => SaleStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancel_reason' => $reason !== '' ? $reason : null,
            ]);

            SaleRevision::create([
                'sale_id' => $sale->id,
                'action' => SaleRevisionAction::Cancelled,
                'user_id' => $userId,
                'reason' => $reason !== '' ? $reason : null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return ['restocked' => $restocked, 'skipped' => $skipped];
        });
    }
}
