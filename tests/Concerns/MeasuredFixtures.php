<?php

namespace Tests\Concerns;

use App\Actions\CreateSale;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Support\Quantity;
use Illuminate\Validation\ValidationException;

trait MeasuredFixtures
{
    protected function shop(array $attributes = []): Shop
    {
        $shop = Shop::create($attributes + ['name' => 'Shop A', 'measured_units_enabled' => true]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]));
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Grocery', 'slug' => 'grocery']);

        return $shop;
    }

    protected function product(Shop $shop, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'shop_id' => $shop->id, 'type' => 'grocery', 'name' => 'Basmati Rice', 'price' => 300, 'cost_price' => 250,
            'stock_quantity' => 0, 'unit' => 'kg', 'details' => ['category' => null],
        ]);
    }

    protected function piece(Shop $shop, array $attributes = []): Product
    {
        return $this->product($shop, $attributes + ['name' => 'Cable', 'unit' => 'piece']);
    }

    protected function batch(Product $product, mixed $quantity, array $attributes = []): StockIn
    {
        $product->increment('stock_quantity', Quantity::normalize($quantity));

        return StockIn::create($attributes + [
            'shop_id' => $product->shop_id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'quantity_remaining' => $quantity, 'unit' => $product->unit,
            'cost_price' => 250, 'selling_price' => 300, 'total_cost' => Quantity::money(250, $quantity),
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'stock_date' => now()->toDateString(),
        ]);
    }

    protected function sell(Product $product, mixed $quantity, array $line = [], float $paid = 0): Sale
    {
        return CreateSale::handle(
            lines: [$line + ['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0,
            customerId: null,
            userId: auth()->id(),
            saleDate: now()->toDateString(),
            amountPaid: $paid,
        );
    }

    protected function assertRefused(callable $action, ?string $message = null): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            if ($message !== null) {
                $this->assertStringContainsString($message, implode(' ', $e->errors() ? array_merge(...array_values($e->errors())) : []));
            }

            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the action to be refused.');
    }
}
