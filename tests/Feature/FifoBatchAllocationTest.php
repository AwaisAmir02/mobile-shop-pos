<?php

namespace Tests\Feature;

use App\Actions\ConsumeStockFifo;
use App\Actions\RestoreStockFifo;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Support\SaleCartMath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FifoBatchAllocationTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithProduct(int $stock = 0): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 999, 'cost_price' => 10, 'stock_quantity' => $stock, 'details' => ['category' => 'Cable']]);

        return [$shop, $owner, $product];
    }

    protected function makeBatch(array $shop_owner_product, int $quantity, float $costPrice, float $sellingPrice, string $date): StockIn
    {
        [$shop, $owner, $product] = $shop_owner_product;

        return StockIn::create([
            'shop_id' => $shop->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'user_id' => $owner->id,
            'quantity' => $quantity,
            'cost_price' => $costPrice,
            'selling_price' => $sellingPrice,
            'quantity_remaining' => $quantity,
            'total_cost' => $costPrice * $quantity,
            'payment_status' => 'paid',
            'amount_paid' => $costPrice * $quantity,
            'stock_date' => $date,
        ]);
    }

    public function test_consume_draws_from_the_oldest_batch_first(): void
    {
        $ctx = $this->shopWithProduct();
        [$shop, $owner, $product] = $ctx;
        $old = $this->makeBatch($ctx, 5, 300, 500, '2026-01-01');
        $new = $this->makeBatch($ctx, 5, 400, 700, '2026-01-10');

        $allocations = ConsumeStockFifo::handle($product, 3);

        $this->assertCount(1, $allocations);
        $this->assertSame($old->id, $allocations[0]['stock_in_id']);
        $this->assertSame(3, $allocations[0]['quantity']);
        $this->assertSame(500.0, $allocations[0]['selling_price']);
        $this->assertSame(2, $old->fresh()->quantity_remaining);
        $this->assertSame(5, $new->fresh()->quantity_remaining);
    }

    public function test_consume_splits_across_two_batches_at_their_own_prices(): void
    {
        $ctx = $this->shopWithProduct();
        [$shop, $owner, $product] = $ctx;
        $old = $this->makeBatch($ctx, 5, 300, 500, '2026-01-01');
        $new = $this->makeBatch($ctx, 5, 400, 700, '2026-01-10');

        $allocations = ConsumeStockFifo::handle($product, 7);

        $this->assertCount(2, $allocations);
        $this->assertSame(5, $allocations[0]['quantity']);
        $this->assertSame(500.0, $allocations[0]['selling_price']);
        $this->assertSame(2, $allocations[1]['quantity']);
        $this->assertSame(700.0, $allocations[1]['selling_price']);
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
    }

    public function test_consume_falls_back_to_product_price_with_zero_batches(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct(stock: 10);

        $allocations = ConsumeStockFifo::handle($product, 4);

        $this->assertCount(1, $allocations);
        $this->assertNull($allocations[0]['stock_in_id']);
        $this->assertSame(4, $allocations[0]['quantity']);
        $this->assertSame(999.0, $allocations[0]['selling_price']);
        $this->assertSame(10.0, $allocations[0]['cost_price']);
    }

    public function test_consume_falls_back_for_the_uncovered_remainder_when_batches_run_short(): void
    {
        $ctx = $this->shopWithProduct(stock: 8);
        [$shop, $owner, $product] = $ctx;
        $batch = $this->makeBatch($ctx, 5, 300, 500, '2026-01-01');

        $allocations = ConsumeStockFifo::handle($product, 8);

        $this->assertCount(2, $allocations);
        $this->assertSame($batch->id, $allocations[0]['stock_in_id']);
        $this->assertSame(5, $allocations[0]['quantity']);
        $this->assertNull($allocations[1]['stock_in_id']);
        $this->assertSame(3, $allocations[1]['quantity']);
        $this->assertSame(999.0, $allocations[1]['selling_price']);
    }

    public function test_allocate_lines_with_no_override_charges_each_batch_its_own_price(): void
    {
        $lines = SaleCartMath::allocateLines(null, 0.0, [
            ['stock_in_id' => 1, 'quantity' => 5, 'cost_price' => 300.0, 'selling_price' => 500.0],
            ['stock_in_id' => 2, 'quantity' => 2, 'cost_price' => 400.0, 'selling_price' => 700.0],
        ]);

        $this->assertCount(2, $lines);
        $this->assertSame(500.0, $lines[0]['unit_price']);
        $this->assertSame(2500.0, $lines[0]['line_total']);
        $this->assertSame(700.0, $lines[1]['unit_price']);
        $this->assertSame(1400.0, $lines[1]['line_total']);
    }

    public function test_allocate_lines_with_an_override_applies_uniformly_but_keeps_each_batchs_cost(): void
    {
        $lines = SaleCartMath::allocateLines(600.0, 0.0, [
            ['stock_in_id' => 1, 'quantity' => 5, 'cost_price' => 300.0, 'selling_price' => 500.0],
            ['stock_in_id' => 2, 'quantity' => 2, 'cost_price' => 400.0, 'selling_price' => 700.0],
        ]);

        $this->assertSame(600.0, $lines[0]['unit_price']);
        $this->assertSame(600.0, $lines[1]['unit_price']);
        $this->assertSame(500.0, $lines[0]['list_price']);
        $this->assertSame(700.0, $lines[1]['list_price']);
        $this->assertSame(300.0, $lines[0]['cost_price']);
        $this->assertSame(400.0, $lines[1]['cost_price']);
    }

    public function test_allocate_lines_prorates_discount_and_sums_exactly_to_the_original(): void
    {
        $lines = SaleCartMath::allocateLines(null, 50.0, [
            ['stock_in_id' => 1, 'quantity' => 5, 'cost_price' => 300.0, 'selling_price' => 500.0],
            ['stock_in_id' => 2, 'quantity' => 2, 'cost_price' => 400.0, 'selling_price' => 700.0],
        ]);

        $totalDiscount = array_sum(array_column($lines, 'discount_amount'));
        $this->assertSame(50.0, $totalDiscount);
    }

    public function test_restore_returns_quantity_to_the_exact_batch_it_came_from(): void
    {
        $ctx = $this->shopWithProduct(stock: 10);
        [$shop, $owner, $product] = $ctx;
        $old = $this->makeBatch($ctx, 5, 300, 500, '2026-01-01');
        $new = $this->makeBatch($ctx, 5, 400, 700, '2026-01-10');

        ConsumeStockFifo::handle($product, 7);
        $product->decrement('stock_quantity', 7);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 3900, 'discount_amount' => 0, 'total' => 3900]);
        $item1 = SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'stock_in_id' => $old->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'quantity' => 5, 'discount_amount' => 0, 'line_total' => 2500]);
        $item2 = SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'stock_in_id' => $new->id, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 700, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 1400]);

        RestoreStockFifo::handle($sale->items()->get());

        $this->assertSame(5, $old->fresh()->quantity_remaining);
        $this->assertSame(5, $new->fresh()->quantity_remaining);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_restore_of_a_legacy_item_with_no_batch_just_restocks_the_product(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct(stock: 3);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'stock_in_id' => null, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 100, 'quantity' => 2, 'discount_amount' => 0, 'line_total' => 200]);

        RestoreStockFifo::handle($sale->items()->get());

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }
}
