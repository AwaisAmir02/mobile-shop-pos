<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProductBatchPricingTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithProduct(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 250, 'cost_price' => 150, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);

        return [$shop, $owner, $product];
    }

    public function test_a_product_with_no_batches_has_no_batches_and_uses_its_own_price(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();

        $this->assertFalse($product->hasBatches());
        $this->assertSame(250.0, $product->currentSellingPrice());
        $this->assertSame(150.0, $product->currentCostPrice());
    }

    public function test_a_product_with_batches_uses_the_oldest_available_batchs_price(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'stock_date' => '2026-01-10']);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 400, 'selling_price' => 700, 'quantity_remaining' => 5, 'stock_date' => '2026-01-01']);

        $this->assertTrue($product->hasBatches());
        $this->assertSame(700.0, $product->currentSellingPrice());
        $this->assertSame(400.0, $product->currentCostPrice());
    }

    public function test_a_product_with_only_exhausted_batches_falls_back_to_its_own_price(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 0, 'stock_date' => '2026-01-01']);

        $this->assertTrue($product->hasBatches());
        $this->assertSame(250.0, $product->currentSellingPrice());
        $this->assertSame(150.0, $product->currentCostPrice());
    }

    public function test_editing_a_product_with_no_batches_can_still_change_price_and_cost_price(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();

        Livewire::test('products.index')
            ->call('openEdit', $product->id)
            ->set('price', '300')
            ->set('cost_price', '180')
            ->call('save');

        $product->refresh();
        $this->assertSame(300.0, (float) $product->price);
        $this->assertSame(180.0, (float) $product->cost_price);
    }

    public function test_editing_a_product_with_batches_ignores_any_price_change_and_keeps_the_original(): void
    {
        [$shop, $owner, $product] = $this->shopWithProduct();
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'stock_date' => '2026-01-01']);

        $test = Livewire::test('products.index')
            ->call('openEdit', $product->id);

        $this->assertTrue($test->get('editingHasBatches'));

        $test->set('price', '999')->set('cost_price', '999')->call('save');

        $product->refresh();
        $this->assertSame(250.0, (float) $product->price);
        $this->assertSame(150.0, (float) $product->cost_price);
    }
}
