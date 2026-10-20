<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInAddToReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_quantity_defaults_to_one_so_a_blank_field_does_not_silently_block_the_first_add(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')->assertSet('quantity', '1');
    }

    public function test_clearing_quantity_still_correctly_blocks_add_to_receipt_with_a_visible_inline_error(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '')
            ->set('costPrice', '130000')
            ->set('sellingPrice', '145000')
            ->call('addLine')
            ->assertHasErrors(['quantity' => 'required'])
            ->assertSee('The quantity field is required.')
            ->assertSet('cart', []);
    }

    public function test_selecting_a_product_syncs_server_side_and_auto_fills_cost_and_selling_price(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'cost_price' => 130000, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->assertSet('costPrice', '130000.00')
            ->assertSet('sellingPrice', '145000.00');
    }

    public function test_adding_two_different_products_to_one_receipt_lists_both_with_a_correct_running_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        $phone = Product::create(['shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'iPhone 13', 'price' => 145000, 'stock_quantity' => 0, 'details' => []]);
        $cable = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB Cable', 'price' => 500, 'stock_quantity' => 0, 'details' => []]);
        $this->actingAs($owner);

        $component = Livewire::test('stock-ins.create')
            ->set('productId', (string) $phone->id)
            ->set('quantity', '2')
            ->set('costPrice', '130000')
            ->set('sellingPrice', '145000')
            ->call('addLine')
            ->assertHasNoErrors()
            ->assertSee('iPhone 13')
            ->assertSee('Add Another Product');

        $this->assertCount(1, $component->get('cart'));

        $component
            ->set('productId', (string) $cable->id)
            ->set('quantity', '10')
            ->set('costPrice', '300')
            ->set('sellingPrice', '500')
            ->call('addLine')
            ->assertHasNoErrors();

        $this->assertCount(2, $component->get('cart'));
        $this->assertEqualsWithDelta(263000.0, $component->instance()->grandTotalCost(), 0.01);

        $component
            ->set('vendorId', (string) $vendor->id)
            ->set('paymentStatus', 'paid')
            ->set('stock_date', now()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, StockIn::where('shop_id', $shop->id)->count());
        $this->assertSame(0, StockIn::where('shop_id', $shop->id)->where('vendor_id', '!=', $vendor->id)->count());

        $phoneStockIn = StockIn::where('product_id', $phone->id)->firstOrFail();
        $cableStockIn = StockIn::where('product_id', $cable->id)->firstOrFail();

        $this->assertSame($vendor->id, $phoneStockIn->vendor_id);
        $this->assertSame($vendor->id, $cableStockIn->vendor_id);
        $this->assertEqualsWithDelta(260000.0, (float) $phoneStockIn->amount_paid, 0.01);
        $this->assertEqualsWithDelta(3000.0, (float) $cableStockIn->amount_paid, 0.01);
    }
}
