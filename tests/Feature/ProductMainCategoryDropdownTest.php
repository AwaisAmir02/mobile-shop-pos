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

class ProductMainCategoryDropdownTest extends TestCase
{
    use RefreshDatabase;

    // ── Bug: Sub-Category dropdown appeared empty for an existing Main Category ──

    public function test_switching_to_accessory_populates_the_sub_category_view_data_with_its_existing_sub_categories(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        // Seeds the 2 builtin Main Categories plus the 7 default Sub-Categories,
        // exactly as a real shop visiting Products for the first time would.
        Livewire::test('products.index');
        AccessoryCategoryOption::ensureDefaultsExist();

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertViewHas('accessoryCategoryOptions', function ($options) {
                $labels = collect($options)->pluck('label');

                return $labels->contains('Cable')
                    && $labels->contains('Charger')
                    && $labels->contains('Case / Cover');
            });
    }

    public function test_a_custom_main_categorys_own_sub_categories_are_not_mixed_up_with_accessorys(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::ensureDefaultsExist($shop->id);
        $accessory = MainCategory::where('slug', 'accessory')->firstOrFail();
        $custom = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Laptops', 'slug' => 'laptops']);

        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Charger']);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $custom->id, 'name' => 'Laptop Bag']);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertViewHas('accessoryCategoryOptions', fn ($options) => collect($options)->pluck('label')->contains('Charger')
                && ! collect($options)->pluck('label')->contains('Laptop Bag'))
            ->set('type', 'laptops')
            ->assertViewHas('accessoryCategoryOptions', fn ($options) => collect($options)->pluck('label')->contains('Laptop Bag')
                && ! collect($options)->pluck('label')->contains('Charger'));
    }

    public function test_mobile_phone_never_shows_accessory_sub_categories(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index');
        AccessoryCategoryOption::ensureDefaultsExist();

        Livewire::test('products.index')
            ->set('type', 'mobile')
            ->assertViewHas('accessoryCategoryOptions', function ($options) {
                $labels = collect($options)->pluck('label');

                return ! $labels->contains('Cable') && ! $labels->contains('Charger');
            });
    }

    /**
     * The actual bug lived in the browser: Alpine's x-data captures the
     * options array once via @js() and never re-evaluates it when Livewire
     * morphs the DOM after a sibling field changes — so the Sub-Category
     * list stayed frozen at whatever it was when the modal first opened,
     * even though the server-side data (proven above) was always correct.
     * Livewire's PHP test harness re-renders full HTML on every call and
     * never runs real DOM morphing, so it cannot reproduce that symptom —
     * what it CAN verify is that the fix mechanism (a wire:key that changes
     * with the Main Category, forcing Alpine to fully reinitialize) is
     * actually present in the rendered markup.
     */
    public function test_the_sub_category_dropdown_carries_a_wire_key_that_changes_with_the_main_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->assertSee('wire:key="category-select-mobile"', false)
            ->set('type', 'accessory')
            ->assertSee('wire:key="category-select-accessory"', false)
            ->assertDontSee('wire:key="category-select-mobile"', false);
    }

    // ── Feature: "+ New Main Category" inline-create ──────────────────────

    public function test_selecting_new_main_category_opens_the_quick_create_modal_and_reverts_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->assertSet('type', 'mobile')
            ->set('type', '__create__')
            ->assertSet('type', 'mobile')
            ->assertDispatched('open-modal', name: 'quick-create-main-category');
    }

    public function test_selecting_new_main_category_from_accessory_reverts_back_to_accessory_not_the_default(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('type', '__create__')
            ->assertSet('type', 'accessory');
    }

    public function test_creating_a_main_category_inline_from_add_product_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $products = Livewire::test('products.index');
        $quickCreate = Livewire::test('main-categories.quick-create');

        $quickCreate
            ->set('name', 'Laptops')
            ->call('save')
            ->assertHasNoErrors();

        $category = MainCategory::where('name', 'Laptops')->firstOrFail();
        $this->assertSame($shop->id, $category->shop_id);
        $this->assertSame('laptops', $category->slug);
        $this->assertFalse($category->is_builtin);

        $products->call('onMainCategoryCreated', $category->slug);
        $products->assertSet('type', 'laptops');

        $products
            ->set('name', 'Dell XPS Bag')
            ->set('price', '2000')
            ->set('stock_quantity', '3')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('laptops', Product::where('name', 'Dell XPS Bag')->value('type'));
    }

    public function test_an_inline_created_main_category_is_tenant_scoped(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $this->actingAs($ownerA);

        Livewire::test('main-categories.quick-create')
            ->set('name', 'Shop A Category')
            ->call('save');

        $category = MainCategory::where('name', 'Shop A Category')->firstOrFail();
        $this->assertSame($shopA->id, $category->shop_id);
        $this->assertNotSame($shopB->id, $category->shop_id);
    }

    public function test_a_duplicate_main_category_name_within_the_same_shop_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        MainCategory::ensureDefaultsExist($shop->id);

        $this->actingAs($owner);

        Livewire::test('main-categories.quick-create')
            ->set('name', 'Accessory')
            ->call('save')
            ->assertHasErrors(['name']);
    }
}
