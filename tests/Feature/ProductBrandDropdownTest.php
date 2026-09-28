<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Brand moved from a free-text input to a managed per-shop dropdown (with
 * inline "+ New Brand" create, mirroring Main Category), and Model became
 * optional. These cover the Add Product form specifically; the identical
 * wiring on the Stock In screen's "quick create product" modal is covered
 * by ProductQuickCreateBrandTest.
 */
class ProductBrandDropdownTest extends TestCase
{
    use RefreshDatabase;

    // ── Model is now optional ─────────────────────────────────────────

    public function test_a_mobile_product_can_be_saved_without_a_model(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('name', 'Galaxy Something')
            ->set('brand', $brand->name)
            ->set('price', '10000')
            ->set('stock_quantity', '2')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Galaxy Something')->firstOrFail();
        $this->assertNull($product->details['model']);
    }

    // ── Brand is now a required dropdown sourced from the Brand list ──

    public function test_saving_without_selecting_a_brand_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('name', 'Some Phone')
            ->set('price', '10000')
            ->set('stock_quantity', '2')
            ->call('save')
            ->assertHasErrors(['brand']);
    }

    public function test_a_brand_name_that_does_not_exist_in_the_shops_brand_list_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('name', 'Some Phone')
            ->set('brand', 'Not A Real Brand')
            ->set('price', '10000')
            ->set('stock_quantity', '2')
            ->call('save')
            ->assertHasErrors(['brand']);
    }

    // ── "+ New Brand" inline-create ────────────────────────────────────

    public function test_selecting_new_brand_opens_the_quick_create_modal_and_reverts_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('brand', 'Samsung')
            ->set('brand', '__create__')
            ->assertSet('brand', 'Samsung')
            ->assertDispatched('open-modal', name: 'quick-create-brand');
    }

    public function test_creating_a_brand_inline_from_add_product_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $products = Livewire::test('products.index');
        $quickCreate = Livewire::test('brands.quick-create');

        $quickCreate
            ->set('name', 'Infinix')
            ->call('save')
            ->assertHasNoErrors();

        $brand = Brand::where('name', 'Infinix')->firstOrFail();
        $this->assertSame($shop->id, $brand->shop_id);

        $products->call('onBrandCreated', $brand->name);
        $products->assertSet('brand', 'Infinix');

        $products
            ->set('name', 'Hot 40')
            ->set('price', '30000')
            ->set('stock_quantity', '5')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Hot 40')->firstOrFail();
        $this->assertSame('Infinix', $product->details['brand']);
    }

    public function test_a_duplicate_brand_name_within_the_same_shop_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        $this->actingAs($owner);

        Livewire::test('brands.quick-create')
            ->set('name', 'Samsung')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_an_inline_created_brand_is_tenant_scoped(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $this->actingAs($ownerA);

        Livewire::test('brands.quick-create')
            ->set('name', 'Shop A Brand')
            ->call('save');

        $brand = Brand::where('name', 'Shop A Brand')->firstOrFail();
        $this->assertSame($shopA->id, $brand->shop_id);
        $this->assertNotSame($shopB->id, $brand->shop_id);
    }

    // ── Tenant isolation of the dropdown itself ───────────────────────

    public function test_a_shops_brand_dropdown_never_shows_another_shops_brands(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        Brand::create(['shop_id' => $shopA->id, 'name' => 'Samsung']);
        Brand::create(['shop_id' => $shopB->id, 'name' => 'Apple']);

        $this->actingAs($ownerA);

        Livewire::test('products.index')
            ->assertViewHas('brands', fn ($brands) => $brands->pluck('name')->contains('Samsung')
                && ! $brands->pluck('name')->contains('Apple'));
    }
}
