<?php

namespace App\Actions;

use App\Enums\SaleRevisionAction;
use App\Enums\SaleStatus;
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
                    'stock_in_id' => $item->stock_in_id,
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'unit_price' => (float) $item->unit_price,
                    'list_price' => $item->list_price !== null ? (float) $item->list_price : null,
                    'cost_price' => $item->cost_price !== null ? (float) $item->cost_price : null,
                    'quantity' => (int) $item->quantity,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_total' => (float) $item->line_total,
                ])->all(),
            ];

            $skipped = RestoreStockFifo::handle($sale->items);
            $skippedNames = array_unique($skipped);

            $restocked = $sale->items
                ->filter(fn ($item) => $item->product_id !== null && ! in_array($item->product_name, $skippedNames, true))
                ->pluck('product_name')
                ->unique()
                ->values()
                ->all();

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

            return ['restocked' => $restocked, 'skipped' => $skippedNames];
        });
    }
}
