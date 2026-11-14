<?php

namespace App\Actions;

use App\Enums\SalePaymentMethod;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\BatchExpiry;
use App\Support\Quantity;
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
        ?string $paymentMethod = null,
        ?int $shopAccountId = null,
        array $installments = [],
    ): Sale {
        return DB::transaction(function () use ($lines, $invoiceDiscount, $customerId, $userId, $saleDate, $amountPaid, $shopId, $attributes, $paymentMethod, $shopAccountId, $installments) {
            $subtotal = 0;
            $allItemsData = [];

            foreach ($lines as $line) {
                $productQuery = Product::whereKey($line['product_id']);

                if ($shopId !== null) {
                    $productQuery->where('shop_id', $shopId);
                }

                $product = $productQuery->lockForUpdate()->firstOrFail();
                $product->unit->assertQuantity($line['quantity'], "lines.{$product->id}", $product->name);
                $quantity = Quantity::normalize($line['quantity']);

                if (Quantity::compare($product->stock_quantity, $quantity) < 0) {
                    throw ValidationException::withMessages([
                        "lines.{$product->id}" => 'Only '.$product->stockLabel()." left in stock for {$product->name}.",
                    ]);
                }

                $allocations = ConsumeStockFifo::handle($product, $quantity);
                BatchExpiry::assertNotDrawingExpired($product, $allocations, "lines.{$product->id}");
                $subLines = SaleCartMath::allocateLines($line['custom_price'], (float) $line['discount'], $allocations);

                foreach ($subLines as $subLine) {
                    $subtotal += $subLine['line_total'];
                    $allItemsData[] = array_merge($subLine, [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'product_type' => $product->type,
                        'unit' => $product->unit,
                    ], SaleItem::warrantyAttributesFor($product));
                }

                $product->decrement('stock_quantity', $quantity);
            }

            $subtotal = round($subtotal, 2);
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

            $sale->payments()->create(array_merge([
                'shop_id' => $sale->shop_id,
                'user_id' => $userId,
                'amount' => $amountPaid,
                'payment_date' => now()->toDateString(),
            ], SalePaymentMethod::paymentAttributes($paymentMethod, $shopAccountId, $sale->shop_id, $amountPaid)));

            if ($installments !== []) {
                self::createInstallments($sale, $installments, $amountPaid);
            }

            return $sale;
        });
    }

    /**
     * @param  list<array{sequence: int, due_date: string, amount: float|string}>  $installments
     */
    protected static function createInstallments(Sale $sale, array $installments, float $amountPaid): void
    {
        if ($sale->customer_id === null) {
            throw ValidationException::withMessages(['customerId' => 'Choose the customer who is paying in instalments.']);
        }

        $remaining = round((float) $sale->total - $amountPaid, 2);
        $scheduled = round(array_sum(array_map(fn ($row) => (float) $row['amount'], $installments)), 2);

        if ($remaining <= 0 || abs($scheduled - $remaining) > 0.009) {
            throw ValidationException::withMessages(['installmentCount' => 'The instalments (Rs '.number_format($scheduled, 2).') must add up to what is left after the down payment (Rs '.number_format(max(0, $remaining), 2).').']);
        }

        $saleDay = $sale->created_at->toDateString();

        foreach (array_values($installments) as $i => $row) {
            if ((float) $row['amount'] <= 0 || Carbon::parse($row['due_date'])->toDateString() < $saleDay) {
                throw ValidationException::withMessages(['installmentCount' => 'Each instalment needs an amount and a due date on or after the sale date.']);
            }

            $sale->installments()->create([
                'shop_id' => $sale->shop_id,
                'sequence' => $i + 1,
                'due_date' => Carbon::parse($row['due_date'])->toDateString(),
                'amount' => round((float) $row['amount'], 2),
            ]);
        }
    }
}
