<?php

namespace App\Actions;

use App\Enums\WriteOffReason;
use App\Models\Product;
use App\Models\StockIn;
use App\Models\StockWriteOff;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Takes stock out exactly the way a sale does, through the same batch path,
 * but with no revenue and no customer. Given a batch, it draws from that one;
 * otherwise from whichever batch would sell next. Purchase records and vendor
 * balances aren't touched: the stock was still bought and still owed for.
 */
class CreateStockWriteOff
{
    public static function handle(Product $product, mixed $quantity, WriteOffReason $reason, ?string $note, ?int $userId, string $date, ?int $stockInId = null): StockWriteOff
    {
        if ($reason === WriteOffReason::Other && blank($note)) {
            throw ValidationException::withMessages(['note' => 'Say what happened when the reason is Other.']);
        }

        return DB::transaction(function () use ($product, $quantity, $reason, $note, $userId, $date, $stockInId) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $product->unit->assertQuantity($quantity, 'quantity', $product->name);
            $quantity = Quantity::normalize($quantity);

            if (Quantity::compare($product->stock_quantity, $quantity) < 0) {
                throw ValidationException::withMessages(['quantity' => 'Only '.$product->stockLabel()." of {$product->name} in stock."]);
            }

            $allocations = $stockInId !== null
                ? ConsumeStockFifo::handleFromBatch($product, self::batchOf($product, $stockInId), $quantity)
                : ConsumeStockFifo::handle($product, $quantity);

            $product->decrement('stock_quantity', $quantity);

            $hasCost = collect($allocations)->contains(fn (array $allocation) => $allocation['cost_price'] !== null);

            $writeOff = StockWriteOff::create([
                'shop_id' => $product->shop_id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'user_id' => $userId,
                'quantity' => $quantity,
                'unit' => $product->unit,
                'reason' => $reason,
                'note' => filled($note) ? $note : null,
                'written_off_on' => $date,
                'total_cost' => $hasCost ? collect($allocations)->sum(fn (array $allocation) => Quantity::money($allocation['cost_price'] ?? 0, $allocation['quantity'])) : null,
            ]);

            foreach ($allocations as $allocation) {
                $writeOff->items()->create([
                    'shop_id' => $product->shop_id,
                    'product_id' => $product->id,
                    'stock_in_id' => $allocation['stock_in_id'],
                    'product_name' => $product->name,
                    'quantity' => $allocation['quantity'],
                    'cost_price' => $allocation['cost_price'],
                ]);
            }

            return $writeOff;
        });
    }

    protected static function batchOf(Product $product, int $stockInId): StockIn
    {
        return StockIn::where('product_id', $product->id)->whereKey($stockInId)->first()
            ?? throw ValidationException::withMessages(['stockInId' => 'That batch isn\'t part of this product.']);
    }
}
