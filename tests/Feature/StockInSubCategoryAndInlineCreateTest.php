<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StockInSubCategoryAndInlineCreateTest extends TestCase
{
    use RefreshDatabase;

    // ── Sub-Category filtering ───────────────────────────────────────

    public function test_selecting_a_sub_category_narrows_the_product_list_further_than_category_alone(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Charger']);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Cable']);

        $charger = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => '20W Charger', 'price' => 1500, 'stock_quantity' => 0, 'details' => ['category' => 'Charger']]);
        $cable = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'USB-C Cable', 'price' => 500, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('categoryFilter', 'accessory')
            ->set('subCategoryFilter', 'Charger')
            ->assertViewHas('products', function ($products) use ($charger, $cable) {
                return $products->pluck('id')->contains($charger->id)
                    && ! $products->pluck('id')->contains($cable->id);
            });
    }

    public function test_changing_sub_category_resets_the_selected_product(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Charger', 'price' => 1500, 'stock_quantity' => 0, 'details' => ['category' => 'Charger']]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('categoryFilter', 'accessory')
            ->set('productId', (string) $product->id)
            ->set('subCategoryFilter', 'Cable')
            ->assertSet('productId', '');
    }

    public function test_changing_main_category_also_resets_the_sub_category_filter(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::ensureDefaultsExist($shop->id);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('categoryFilter', 'accessory')
            ->set('subCategoryFilter', 'Charger')
            ->set('categoryFilter', 'mobile')
            ->assertSet('subCategoryFilter', '');
    }

    // ── Inline-create: Main Category ─────────────────────────────────

    public function test_selecting_new_main_category_opens_the_quick_create_modal_and_reverts_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->assertSet('categoryFilter', '')
            ->set('categoryFilter', '__create__')
            ->assertSet('categoryFilter', '')
            ->assertDispatched('open-modal', name: 'quick-create-main-category');
    }

    public function test_creating_a_main_category_inline_from_stock_in_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $stockIns = Livewire::test('stock-ins.create');
        $quickCreate = Livewire::test('main-categories.quick-create');

        $quickCreate->set('name', 'Laptops')->call('save')->assertHasNoErrors();

        $category = MainCategory::where('name', 'Laptops')->firstOrFail();

        $stockIns->call('onMainCategoryCreated', $category->slug);
        $stockIns->assertSet('categoryFilter', $category->slug);
    }

    // ── Inline-create: Sub-Category ──────────────────────────────────

    public function test_selecting_new_sub_category_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('subCategoryFilter', '__create__')
            ->assertSet('subCategoryFilter', '')
            ->assertDispatched('open-modal', name: 'quick-create-accessory-category');
    }

    public function test_creating_a_sub_category_inline_from_stock_in_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::ensureDefaultsExist($shop->id);

        $this->actingAs($owner);

        $stockIns = Livewire::test('stock-ins.create')->set('categoryFilter', 'accessory');
        $quickCreate = Livewire::test('accessory-categories.quick-create', ['defaultMainCategorySlug' => 'accessory']);

        $quickCreate->set('name', 'Motherboard')->call('save')->assertHasNoErrors();

        $stockIns->call('onAccessoryCategoryCreated', 'Motherboard');
        $stockIns->assertSet('subCategoryFilter', 'Motherboard');
    }

    // ── Inline-create: Product ───────────────────────────────────────

    public function test_selecting_new_product_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', '__create__')
            ->assertSet('productId', '')
            ->assertDispatched('open-modal', name: 'quick-create-product');
    }

    public function test_creating_a_product_inline_from_stock_in_creates_it_and_selects_it_and_syncs_filters(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Power Bank']);

        $this->actingAs($owner);

        $stockIns = Livewire::test('stock-ins.create');
        $quickCreate = Livewire::test('products.quick-create', ['defaultMainCategorySlug' => 'accessory']);

        $quickCreate
            ->set('type', 'accessory')
            ->set('name', '10000mAh Power Bank')
            ->set('price', '3500')
            ->set('stock_quantity', '0')
            ->set('category', 'Power Bank')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', '10000mAh Power Bank')->firstOrFail();
        $this->assertSame($shop->id, $product->shop_id);
        $this->assertSame('accessory', $product->type);
        $this->assertSame('Power Bank', $product->details['category']);

        $stockIns->call('onProductCreated', $product->id, $product->type, $product->details['category']);
        $stockIns->assertSet('productId', (string) $product->id);
        $stockIns->assertSet('categoryFilter', 'accessory');
        $stockIns->assertSet('subCategoryFilter', 'Power Bank');
    }

    public function test_an_inline_created_product_is_tenant_scoped(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $this->actingAs($ownerA);

        Livewire::test('products.quick-create')
            ->set('type', 'mobile')
            ->set('name', 'Shop A Phone')
            ->set('brand', 'Samsung')
            ->set('model', 'Galaxy A15')
            ->set('price', '50000')
            ->set('stock_quantity', '1')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Shop A Phone')->firstOrFail();
        $this->assertSame($shopA->id, $product->shop_id);
        $this->assertNotSame($shopB->id, $product->shop_id);
    }

    public function test_stock_in_screen_and_its_filters_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $accessoryA = MainCategory::create(['shop_id' => $shopA->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        $accessoryB = MainCategory::create(['shop_id' => $shopB->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);

        AccessoryCategoryOption::create(['shop_id' => $shopA->id, 'main_category_id' => $accessoryA->id, 'name' => 'Shop A Sub-Category']);
        AccessoryCategoryOption::create(['shop_id' => $shopB->id, 'main_category_id' => $accessoryB->id, 'name' => 'Shop B Sub-Category']);

        Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'Shop A Product', 'price' => 100, 'stock_quantity' => 0, 'details' => []]);
        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Shop B Product', 'price' => 9999, 'stock_quantity' => 0, 'details' => []]);

        $this->actingAs($ownerA);

        Livewire::test('stock-ins.create')
            ->set('categoryFilter', 'accessory')
            ->assertSee('Shop A Sub-Category')
            ->assertDontSee('Shop B Sub-Category')
            ->assertSee('Shop A Product')
            ->assertDontSee('Shop B Product');
    }
}
