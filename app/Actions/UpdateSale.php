<?php

namespace App\Actions;

use App\Enums\QuantityUnit;
use App\Enums\SaleRevisionAction;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleRevision;
use App\Models\Scopes\ExcludesCancelledSalesScope;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use App\Support\SaleCartMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateSale
{
    /**
     * @param  array<int, array{product_id: ?int, product_name: string, product_type: string, quantity: int|float|string, unit?: string, discount: float, custom_price: ?float}>  $lines
     */
    public static function handle(Sale $sale, array $lines, float $invoiceDiscount, ?int $customerId, ?int $userId, string $saleDate): Sale
    {
        return DB::transaction(function () use ($sale, $lines, $invoiceDiscount, $customerId, $userId, $saleDate) {
            $sale = Sale::withoutGlobalScope(ExcludesCancelledSalesScope::class)->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new \RuntimeException('A cancelled sale cannot be edited.');
            }

            $sale->loadMissing(['items', 'payments']);

            $alreadyPaid = (float) $sale->payments()->sum('amount');

            $snapshot = [
                'subtotal' => (float) $sale->subtotal,
                'discount_amount' => (float) $sale->discount_amount,
                'total' => (float) $sale->total,
                'customer_id' => $sale->customer_id,
                'created_at' => $sale->created_at->toIso8601String(),
                'items' => $sale->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'stock_in_id' => $item->stock_in_id,
                    'product_name' => $item->product_name,
                    'product_type' => $item->product_type,
                    'unit_price' => (float) $item->unit_price,
                    'list_price' => $item->list_price !== null ? (float) $item->list_price : null,
                    'cost_price' => $item->cost_price !== null ? (float) $item->cost_price : null,
                    'quantity' => $item->quantity,
                    'unit' => $item->unit->value,
                    'discount_amount' => (float) $item->discount_amount,
                    'line_total' => (float) $item->line_total,
                ])->all(),
            ];

            $alreadyHeld = $sale->items->whereNotNull('stock_in_id')->groupBy('stock_in_id')
                ->map(fn ($items) => Quantity::sum($items->pluck('quantity')))->all();

            RestoreStockFifo::handle($sale->items);

            $subtotal = 0.0;
            $newItemsData = [];

            foreach ($lines as $line) {
                if ($line['product_id'] === null) {
                    // A line whose product is gone keeps the unit it was sold in.
                    $unit = QuantityUnit::fromValue($line['unit'] ?? QuantityUnit::Piece);
                    $unit->assertQuantity($line['quantity'], 'lines', $line['product_name']);
                    $unitPrice = (float) $line['custom_price'];
                    $discount = (float) $line['discount'];
                    $lineTotal = SaleCartMath::lineTotal(['unit_price' => $unitPrice, 'quantity' => $line['quantity'], 'discount' => $discount]);
                    $subtotal += $lineTotal;

                    $newItemsData[] = [
                        'product_id' => null,
                        'stock_in_id' => null,
                        'product_name' => $line['product_name'],
                        'product_type' => $line['product_type'],
                        'unit_price' => $unitPrice,
                        'list_price' => $unitPrice,
                        'cost_price' => null,
                        'quantity' => Quantity::normalize($line['quantity']),
                        'unit' => $unit,
                        'discount_amount' => $discount,
                        'line_total' => $lineTotal,
                    ];

                    continue;
                }

                $product = Product::whereKey($line['product_id'])->lockForUpdate()->first();

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'lines' => "A product on this sale no longer exists and cannot have its quantity set.",
                    ]);
                }

                $product->unit->assertQuantity($line['quantity'], 'lines', $product->name);
                $quantity = Quantity::normalize($line['quantity']);

                if (Quantity::compare($product->stock_quantity, $quantity) < 0) {
                    throw ValidationException::withMessages([
                        'lines' => 'Only '.$product->stockLabel()." left in stock for {$product->name}.",
                    ]);
                }

                $allocations = ConsumeStockFifo::handle($product, $quantity);
                BatchExpiry::assertNotDrawingExpired($product, $allocations, 'lines', $alreadyHeld);
                $overridePrice = $line['custom_price'] !== null ? (float) $line['custom_price'] : null;
                $subLines = SaleCartMath::allocateLines($overridePrice, (float) $line['discount'], $allocations);

                foreach ($subLines as $subLine) {
                    $subtotal += $subLine['line_total'];
                    $newItemsData[] = [
                        'product_id' => $product->id,
                        'stock_in_id' => $subLine['stock_in_id'],
                        'product_name' => $line['product_name'],
                        'product_type' => $line['product_type'],
                        'unit_price' => $subLine['unit_price'],
                        'list_price' => $subLine['list_price'],
                        'cost_price' => $subLine['cost_price'],
                        'quantity' => $subLine['quantity'],
                        'unit' => $product->unit,
                        'discount_amount' => $subLine['discount_amount'],
                        'line_total' => $subLine['line_total'],
                    ];
                }

                $product->decrement('stock_quantity', $quantity);
            }

            // Whole paisas, so "less than already paid" can't trip on float noise.
            $subtotal = round($subtotal, 2);
            $clampedDiscount = min($invoiceDiscount, $subtotal);
            $newTotal = round(max(0.0, $subtotal - $clampedDiscount), 2);

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

            $sale->items()->delete();

            foreach ($newItemsData as $itemData) {
                $sale->items()->create($itemData);
            }

            $sale->fill([
                'customer_id' => $customerId,
                'subtotal' => $subtotal,
                'discount_amount' => $clampedDiscount,
                'total' => $newTotal,
            ]);
            $sale->created_at = Carbon::parse($saleDate)->setTimeFrom($sale->created_at);
            $sale->save();

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
