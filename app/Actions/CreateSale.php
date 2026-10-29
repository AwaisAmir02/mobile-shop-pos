<?php

namespace App\Actions;

use App\Models\Product;
use App\Models\Sale;
use App\Support\SaleCartMath;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateSale
{
    public static function handle(
        array $lines,
        float $invoiceDiscount,
        ?int $customerId,
        ?int $userId,
        string $saleDate,
        float $amountPaid,
        ?int $shopId = null,
        array $attributes = [],
    ): Sale {
        return DB::transaction(function () use ($lines, $invoiceDiscount, $customerId, $userId, $saleDate, $amountPaid, $shopId, $attributes) {
            $subtotal = 0;
            $allItemsData = [];

            foreach ($lines as $line) {
                $productQuery = Product::whereKey($line['product_id']);

                if ($shopId !== null) {
                    $productQuery->where('shop_id', $shopId);
                }

                $product = $productQuery->lockForUpdate()->firstOrFail();
                $quantity = (int) $line['quantity'];

                if ($product->stock_quantity < $quantity) {
                    throw ValidationException::withMessages([
                        "lines.{$product->id}" => "Only {$product->stock_quantity} left in stock for {$product->name}.",
                    ]);
                }

                $allocations = ConsumeStockFifo::handle($product, $quantity);
                $subLines = SaleCartMath::allocateLines($line['custom_price'], (float) $line['discount'], $allocations);

                foreach ($subLines as $subLine) {
                    $subtotal += $subLine['line_total'];
                    $allItemsData[] = array_merge($subLine, [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_type' => $product->type,
                    ]);
                }

                $product->decrement('stock_quantity', $quantity);
            }

            $invoiceDiscount = min($invoiceDiscount, $subtotal);

            $sale = new Sale(array_merge($attributes, [
                'user_id' => $userId,
                'customer_id' => $customerId,
                'subtotal' => $subtotal,
                'discount_amount' => $invoiceDiscount,
                'total' => $subtotal - $invoiceDiscount,
            ]));

            if ($shopId !== null) {
                $sale->shop_id = $shopId;
            }

            $sale->created_at = Carbon::parse($saleDate)->setTimeFrom(now());
            $sale->save();

            foreach ($allItemsData as $itemData) {
                $sale->items()->create(array_merge($itemData, ['shop_id' => $sale->shop_id]));
            }

            $sale->payments()->create([
                'shop_id' => $sale->shop_id,
                'user_id' => $userId,
                'amount' => $amountPaid,
                'payment_date' => now()->toDateString(),
            ]);

            return $sale;
        });
    }
}
