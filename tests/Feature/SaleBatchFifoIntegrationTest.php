<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleBatchFifoIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithBatchedProduct(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 300, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $old = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'total_cost' => 1500, 'payment_status' => 'paid', 'amount_paid' => 1500, 'stock_date' => '2026-01-01']);
        $new = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'cost_price' => 400, 'selling_price' => 700, 'quantity_remaining' => 5, 'total_cost' => 2000, 'payment_status' => 'paid', 'amount_paid' => 2000, 'stock_date' => '2026-01-10']);

        return [$shop, $owner, $product, $old, $new];
    }

    public function test_a_sale_spanning_two_batches_is_split_correctly_on_checkout(): void
    {
        [$shop, $owner, $product, $old, $new] = $this->shopWithBatchedProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 7)
            ->call('completeSale');

        $sale = Sale::first();
        $items = $sale->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame(5, $items[0]->quantity);
        $this->assertSame(500.0, (float) $items[0]->unit_price);
        $this->assertSame($old->id, $items[0]->stock_in_id);
        $this->assertSame(2, $items[1]->quantity);
        $this->assertSame(700.0, (float) $items[1]->unit_price);
        $this->assertSame($new->id, $items[1]->stock_in_id);
        $this->assertSame(3900.0, (float) $sale->total);
        $this->assertSame(0, $old->fresh()->quantity_remaining);
        $this->assertSame(3, $new->fresh()->quantity_remaining);
        $this->assertSame(3, $product->fresh()->stock_quantity);
    }

    public function test_an_override_on_a_multi_batch_line_applies_uniformly_to_every_split_row(): void
    {
        [$shop, $owner, $product, $old, $new] = $this->shopWithBatchedProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 7)
            ->set("cart.{$product->id}.custom_price", '600')
            ->call('completeSale');

        $sale = Sale::first();
        $items = $sale->items()->orderBy('id')->get();

        $this->assertCount(2, $items);
        $this->assertSame(600.0, (float) $items[0]->unit_price);
        $this->assertSame(600.0, (float) $items[1]->unit_price);
        $this->assertSame(500.0, (float) $items[0]->list_price);
        $this->assertSame(700.0, (float) $items[1]->list_price);
        $this->assertSame(300.0, (float) $items[0]->cost_price);
        $this->assertSame(400.0, (float) $items[1]->cost_price);
        $this->assertSame(4200.0, (float) $sale->total);
    }

    public function test_a_sale_exceeding_total_available_stock_across_all_batches_is_rejected(): void
    {
        [$shop, $owner, $product] = $this->shopWithBatchedProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 11)
            ->call('completeSale')
            ->assertHasErrors(["cart.{$product->id}.quantity"]);

        $this->assertSame(0, Sale::count());
    }

    public function test_cancelling_a_batch_spanning_sale_restores_both_batches_exactly(): void
    {
        [$shop, $owner, $product, $old, $new] = $this->shopWithBatchedProduct();

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 7)
            ->call('completeSale');

        $sale = Sale::first();
        CancelSale::handle($sale, $owner->id, null);

        $this->assertSame(5, $old->fresh()->quantity_remaining);
        $this->assertSame(5, $new->fresh()->quantity_remaining);
        $this->assertSame(10, $product->fresh()->stock_quantity);
    }

    public function test_editing_a_batch_spanning_sales_quantity_restores_old_batches_and_reconsumes_fresh(): void
    {
        [$shop, $owner, $product, $old, $new] = $this->shopWithBatchedProduct();
        $customer = \App\Models\Customer::create(['shop_id' => $shop->id, 'name' => 'Ali']);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 7)
            ->set('amountPaidNow', '1000')
            ->set('customerId', (string) $customer->id)
            ->call('completeSale');

        $sale = Sale::first()->fresh();

        Livewire::test('sales.edit', ['sale' => $sale])
            ->set("cart.{$product->id}.quantity", 3)
            ->call('saveChanges');

        $this->assertSame(2, $old->fresh()->quantity_remaining);
        $this->assertSame(5, $new->fresh()->quantity_remaining);
        $this->assertSame(7, $product->fresh()->stock_quantity);

        $items = $sale->fresh()->items()->orderBy('id')->get();
        $this->assertCount(1, $items);
        $this->assertSame(3, $items[0]->quantity);
        $this->assertSame($old->id, $items[0]->stock_in_id);
    }

    public function test_a_sale_with_zero_batches_falls_back_to_the_products_own_price(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 250, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set("cart.{$product->id}.quantity", 4)
            ->call('completeSale');

        $sale = Sale::first();
        $items = $sale->items;

        $this->assertCount(1, $items);
        $this->assertNull($items[0]->stock_in_id);
        $this->assertSame(250.0, (float) $items[0]->unit_price);
        $this->assertSame(1000.0, (float) $sale->total);
    }
}
