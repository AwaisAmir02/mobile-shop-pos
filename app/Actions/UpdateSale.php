<?php

namespace App\Actions;

use App\Enums\SaleRevisionAction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRevision;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use App\Support\SaleCartMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSale
{
    /**
     * @param  array<int, array{product_id: int, product_name: string, product_type: string, unit_price: float, list_price: float, quantity: int, discount: float}>  $lines
     */
    public static function handle(Sale $sale, array $lines, float $invoiceDiscount, ?int $customerId, ?int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $lines, $invoiceDiscount, $customerId, $userId) {
            $sale = Sale::withoutGlobalScope(ExcludesCancelledSalesScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('A cancelled sale cannot be edited.');
            }

            $sale->loadMissing(['items', 'payments']);

            $alreadyPaid = (float) $sale->payments()->sum('amount');

            $subtotal = SaleCartMath::subtotal(collect($lines)->map(fn ($l) => [
                'unit_price' => $l['unit_price'], 'quantity' => $l['quantity'], 'discount' => $l['discount'],
            ])->all());
            $clampedDiscount = min($invoiceDiscount, $subtotal);
            $newTotal = max(0.0, $subtotal - $clampedDiscount);

            if ($newTotal < $alreadyPaid) {
                throw ValidationException::withMessages([
                    'total' => "New total (Rs ".number_format($newTotal, 2).") is less than Rs ".number_format($alreadyPaid, 2)." already paid against this sale.",
                ]);
            }

            $amountDueAfter = max(0.0, $newTotal - $alreadyPaid);

            if ($customerId === null && $amountDueAfter > 0) {
                throw ValidationException::withMessages([
                    'customerId' => 'A customer is required while this sale still has a due balance.',
                ]);
            }

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

            $oldQuantities = [];
            foreach ($sale->items as $item) {
                if ($item->product_id !== null) {
                    $oldQuantities[$item->product_id] = ($oldQuantities[$item->product_id] ?? 0) + $item->quantity;
                }
            }

            $newQuantities = [];
            foreach ($lines as $line) {
                $newQuantities[$line['product_id']] = ($newQuantities[$line['product_id']] ?? 0) + $line['quantity'];
            }

            $productIds = array_unique([...array_keys($oldQuantities), ...array_keys($newQuantities)]);

            foreach ($productIds as $productId) {
                $delta = ($newQuantities[$productId] ?? 0) - ($oldQuantities[$productId] ?? 0);

                if ($delta === 0) {
                    continue;
                }

                $product = Product::whereKey($productId)->lockForUpdate()->first();

                if ($product === null) {
                    if ($delta > 0) {
                        throw ValidationException::withMessages([
                            'lines' => "A product on this sale no longer exists and cannot have its quantity increased.",
                        ]);
                    }

                    continue;
                }

                if ($delta > 0 && $product->stock_quantity < $delta) {
                    throw ValidationException::withMessages([
                        'lines' => "Only {$product->stock_quantity} left in stock for {$product->name}.",
                    ]);
                }

                $product->decrement('stock_quantity', $delta);
            }

            $sale->items()->delete();

            foreach ($lines as $line) {
                $lineTotal = SaleCartMath::lineTotal([
                    'unit_price' => $line['unit_price'], 'quantity' => $line['quantity'], 'discount' => $line['discount'],
                ]);

                $sale->items()->create([
                    'product_id' => $line['product_id'],
                    'product_name' => $line['product_name'],
                    'product_type' => $line['product_type'],
                    'unit_price' => $line['unit_price'],
                    'list_price' => $line['list_price'],
                    'quantity' => $line['quantity'],
                    'discount_amount' => $line['discount'],
                    'line_total' => $lineTotal,
                ]);
            }

            $sale->update([
                'customer_id' => $customerId,
                'subtotal' => $subtotal,
                'discount_amount' => $clampedDiscount,
                'total' => $newTotal,
            ]);

            SaleRevision::create([
                'sale_id' => $sale->id,
                'action' => SaleRevisionAction::Edited,
                'user_id' => $userId,
                'reason' => null,
                'snapshot' => $snapshot,
                'created_at' => now(),
            ]);

            return $sale->fresh(['items', 'payments']);
        });
    }
}
