<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_stock_in_increments_the_products_stock_and_logs_an_entry(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'mobile',
            'name' => 'Galaxy A15',
            'price' => 42000,
            'stock_quantity' => 5,
            'details' => ['brand' => 'Samsung', 'model' => 'Galaxy A15'],
        ]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('stock_date', '2026-08-20')
            ->set('note', 'Supplier: ABC Traders')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(15, $product->fresh()->stock_quantity);

        $entry = StockIn::first();
        $this->assertSame($shop->id, $entry->shop_id);
        $this->assertSame($product->id, $entry->product_id);
        $this->assertSame('Galaxy A15', $entry->product_name);
        $this->assertSame(10, $entry->quantity);
        $this->assertSame('Supplier: ABC Traders', $entry->note);
        $this->assertSame($owner->id, $entry->user_id);
    }

    public function test_multiple_stock_in_entries_accumulate_correctly(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Charger',
            'price' => 1500,
            'stock_quantity' => 0,
            'details' => [],
        ]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->set('quantity', '20')->set('stock_date', '2026-08-01')
            ->call('save');

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->set('quantity', '15')->set('stock_date', '2026-08-10')
            ->call('save');

        $this->assertSame(35, $product->fresh()->stock_quantity);
        $this->assertSame(2, StockIn::count());
    }

    public function test_a_tampered_product_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $productB = Product::create([
            'shop_id' => $shopB->id,
            'type' => 'accessory',
            'name' => 'Shop B Charger',
            'price' => 1500,
            'stock_quantity' => 10,
            'details' => [],
        ]);

        $this->actingAs($ownerA);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $productB->id)
            ->set('quantity', '5')
            ->set('stock_date', '2026-08-01')
            ->call('save')
            ->assertHasErrors(['productId']);

        $this->assertSame(10, $productB->fresh()->stock_quantity);
        $this->assertSame(0, StockIn::count());
    }

    public function test_history_is_filterable_by_date_range_and_product(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $productA = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'stock_quantity' => 0, 'details' => []]);
        $productB = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 300, 'stock_quantity' => 0, 'details' => []]);

        StockIn::create(['shop_id' => $shop->id, 'product_id' => $productA->id, 'product_name' => 'Charger', 'user_id' => $owner->id, 'quantity' => 10, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $productB->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 25, 'stock_date' => '2026-08-15']);

        $this->actingAs($owner);

        // Assert on quantities, not product names — the product-filter <select>
        // always lists every product's name as an option regardless of the
        // active filter, so asserting product-name absence would false-fail.
        Livewire::test('stock-ins.history')
            ->set('from', '2026-08-10')
            ->assertSee('+25')
            ->assertDontSee('+10');

        Livewire::test('stock-ins.history')
            ->set('productFilter', (string) $productA->id)
            ->assertSee('+10')
            ->assertDontSee('+25');
    }

    public function test_stock_ins_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $productA = Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'A Product', 'price' => 100, 'stock_quantity' => 0, 'details' => []]);
        $productB = Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'B Product', 'price' => 100, 'stock_quantity' => 0, 'details' => []]);

        StockIn::create(['shop_id' => $shopA->id, 'product_id' => $productA->id, 'product_name' => 'A Product', 'quantity' => 5, 'stock_date' => '2026-08-01']);
        StockIn::create(['shop_id' => $shopB->id, 'product_id' => $productB->id, 'product_name' => 'B Product', 'quantity' => 999, 'stock_date' => '2026-08-01']);

        $this->actingAs($ownerA);

        Livewire::test('stock-ins.history')
            ->assertSee('A Product')
            ->assertDontSee('B Product');
    }

    public function test_a_role_without_stock_ins_access_cannot_reach_stock_in_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('stock-ins.index'))->assertForbidden();
        $this->get(route('stock-ins.history'))->assertForbidden();
    }

    public function test_a_role_with_stock_ins_access_can_reach_stock_in_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['stock-ins']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('stock-ins.index'))->assertOk();
        $this->get(route('stock-ins.history'))->assertOk();
    }
}
