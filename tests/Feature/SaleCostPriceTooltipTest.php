<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleCostPriceTooltipTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    public function test_the_cart_shows_the_products_own_cost_price_when_it_has_no_batches(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSet("cart.{$product->id}.cost_price", 150.0);
    }

    public function test_the_cart_shows_the_oldest_available_batchs_cost_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'stock_date' => '2026-01-01']);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSet("cart.{$product->id}.cost_price", 300.0);
    }

    public function test_editing_an_existing_line_shows_its_own_frozen_cost_price_not_a_fresh_lookup(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $newBatch = StockIn::create(['shop_id' => $shop->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'cost_price' => 500, 'selling_price' => 800, 'quantity_remaining' => 5, 'stock_date' => '2026-02-01']);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'stock_in_id' => null, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'cost_price' => 300, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        $this->assertSame(500.0, $product->currentCostPrice());

        Livewire::test('sales.edit', ['sale' => $sale])
            ->assertSet("cart.{$product->id}.cost_price", 300.0);
    }

    public function test_a_freshly_added_line_during_an_edit_uses_a_live_lookup_not_a_frozen_value(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $existingProduct = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $newProduct = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1000, 'cost_price' => 600, 'stock_quantity' => 10, 'details' => ['category' => 'Charger']]);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $existingProduct->id, 'stock_in_id' => null, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'cost_price' => 150, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        Livewire::test('sales.edit', ['sale' => $sale])
            ->call('addToCart', $newProduct->id)
            ->assertSet("cart.{$newProduct->id}.cost_price", 600.0);
    }

    public function test_the_icon_does_not_leak_another_lines_cost_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $productA = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $productB = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1000, 'cost_price' => 600, 'stock_quantity' => 10, 'details' => ['category' => 'Charger']]);

        $test = Livewire::test('sales.create')
            ->call('addToCart', $productA->id)
            ->call('addToCart', $productB->id);

        $this->assertEquals(150.0, $test->get('cart')[$productA->id]['cost_price']);
        $this->assertEquals(600.0, $test->get('cart')[$productB->id]['cost_price']);
    }

    public function test_a_role_without_the_permission_never_receives_a_cost_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $test = Livewire::test('sales.create')->call('addToCart', $product->id);

        $this->assertNull($test->get('cart')[$product->id]['cost_price']);
        $test->assertDontSee('Cost: Rs 150.00');
    }

    public function test_a_role_with_the_permission_receives_the_cost_price(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Cashier', 'permissions' => ['sales', 'sales-view-cost-price']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $test = Livewire::test('sales.create')->call('addToCart', $product->id);

        $this->assertSame(150.0, $test->get('cart')[$product->id]['cost_price']);
        $test->assertSee('Cost: Rs 150.00');
    }

    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['sales', 'sales-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $test = Livewire::test('sales.create')->call('addToCart', $product->id);

        $this->assertNull($test->get('cart')[$product->id]['cost_price']);
    }

    public function test_super_admin_can_disable_sales_view_cost_price_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleScreen', 'sales-view-cost-price');

        $this->assertTrue($shopA->fresh()->isScreenDisabled('sales-view-cost-price'));
        $this->assertFalse($shopB->fresh()->isScreenDisabled('sales-view-cost-price'));
    }

    public function test_super_admin_disabling_sales_view_cost_price_blocks_even_the_owner(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $shop->update(['disabled_screens' => ['sales-view-cost-price']]);

        $test = Livewire::test('sales.create')->call('addToCart', $product->id);

        $this->assertNull($test->get('cart')[$product->id]['cost_price']);
    }

    protected function assertMarkupIsInsideComponentBoundary(string $routeName, array $routeParams, string $componentName, string $needle): void
    {
        $html = $this->get(route($routeName, $routeParams))->getContent();

        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches, PREG_OFFSET_CAPTURE);

        $componentSnapshotOffset = null;
        foreach ($matches[1] as [$raw, $offset]) {
            $snapshot = json_decode(html_entity_decode($raw), true);

            if (($snapshot['memo']['name'] ?? null) === $componentName) {
                $componentSnapshotOffset = $offset;
                break;
            }
        }

        $this->assertNotNull($componentSnapshotOffset, "Could not find a wire:snapshot for [{$componentName}].");

        $needleOffset = strpos($html, $needle);
        $this->assertNotFalse($needleOffset, "Markup \"{$needle}\" not found on the page.");

        $this->assertGreaterThan(
            $componentSnapshotOffset,
            $needleOffset,
            "\"{$needle}\" appears before [{$componentName}]'s own wire:snapshot — it's sitting outside the ".
            'component\'s Livewire boundary.'
        );
    }

    public function test_the_cost_price_tooltip_is_inside_the_edit_sale_components_own_boundary(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'cost_price' => 150, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'stock_in_id' => null, 'product_name' => 'Cable', 'product_type' => 'accessory', 'unit_price' => 500, 'list_price' => 500, 'cost_price' => 150, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 500]);

        $this->assertMarkupIsInsideComponentBoundary('sales.edit', [$sale], 'sales.edit', 'Cost: Rs 150.00');
    }
}
