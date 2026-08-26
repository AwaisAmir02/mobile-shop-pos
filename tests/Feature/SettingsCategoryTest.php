<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('categoryName', 'Accessories')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertTrue(Category::where('name', 'Accessories')->exists());
    }

    public function test_owner_can_rename_a_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openCategoryEdit', $category->id)
            ->set('categoryName', 'Phone Accessories')
            ->call('saveCategory')
            ->assertHasNoErrors();

        $this->assertSame('Phone Accessories', $category->fresh()->name);
    }

    public function test_owner_can_add_a_sub_category_scoped_to_a_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openSubCategoryManager', $category->id)
            ->set('subCategoryName', 'Chargers')
            ->call('saveSubCategory')
            ->assertHasNoErrors();

        $subCategory = SubCategory::where('name', 'Chargers')->firstOrFail();
        $this->assertSame($category->id, $subCategory->category_id);
        $this->assertSame($shop->id, $subCategory->shop_id);
    }

    public function test_same_sub_category_name_can_exist_under_different_categories(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $categoryA = Category::create(['shop_id' => $shop->id, 'name' => 'Mobiles']);
        $categoryB = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openSubCategoryManager', $categoryA->id)
            ->set('subCategoryName', 'Other')
            ->call('saveSubCategory')
            ->assertHasNoErrors();

        Livewire::test('settings.index')
            ->call('openSubCategoryManager', $categoryB->id)
            ->set('subCategoryName', 'Other')
            ->call('saveSubCategory')
            ->assertHasNoErrors();

        $this->assertSame(2, SubCategory::where('name', 'Other')->count());
    }

    public function test_deleting_a_category_in_use_on_a_product_is_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);

        Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'category_id' => $category->id,
            'name' => 'Cable',
            'price' => 100,
            'stock_quantity' => 10,
            'details' => ['category' => 'cable'],
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteCategory', $category->id);

        $this->assertNotNull($category->fresh());
    }

    public function test_deleting_a_category_whose_sub_category_is_in_use_on_a_product_is_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);
        $subCategory = SubCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Chargers']);

        Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'name' => 'Fast Charger',
            'price' => 1500,
            'stock_quantity' => 5,
            'details' => ['category' => 'charger'],
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteCategory', $category->id);

        $this->assertNotNull($category->fresh());
    }

    public function test_deleting_a_sub_category_in_use_is_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);
        $subCategory = SubCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Chargers']);

        Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'category_id' => $category->id,
            'sub_category_id' => $subCategory->id,
            'name' => 'Fast Charger',
            'price' => 1500,
            'stock_quantity' => 5,
            'details' => ['category' => 'charger'],
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteSubCategory', $subCategory->id);

        $this->assertNotNull($subCategory->fresh());
    }

    public function test_deleting_an_unused_category_cascades_to_its_unused_sub_categories(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);
        $subCategory = SubCategory::create(['shop_id' => $shop->id, 'category_id' => $category->id, 'name' => 'Chargers']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteCategory', $category->id);

        $this->assertNull($category->fresh());
        $this->assertNull($subCategory->fresh());
    }

    public function test_a_product_can_be_created_with_a_category_and_sub_category_and_the_sub_category_list_filters_by_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $categoryA = Category::create(['shop_id' => $shop->id, 'name' => 'Mobiles']);
        $subCategoryA = SubCategory::create(['shop_id' => $shop->id, 'category_id' => $categoryA->id, 'name' => 'Android']);

        $categoryB = Category::create(['shop_id' => $shop->id, 'name' => 'Accessories']);
        SubCategory::create(['shop_id' => $shop->id, 'category_id' => $categoryB->id, 'name' => 'Chargers']);

        $this->actingAs($owner);

        $component = Livewire::test('products.index')
            ->set('categoryId', $categoryA->id)
            ->assertSee('Android')
            ->assertDontSee('Chargers');

        $component
            ->set('type', 'accessory')
            ->set('subCategoryId', $subCategoryA->id)
            ->set('name', 'Test Product')
            ->set('price', '100')
            ->set('stock_quantity', '10')
            ->set('category', 'cable')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Test Product')->firstOrFail();
        $this->assertSame($categoryA->id, $product->category_id);
        $this->assertSame($subCategoryA->id, $product->sub_category_id);
    }

    public function test_categories_and_sub_categories_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        Category::create(['shop_id' => $shopA->id, 'name' => 'Category A']);
        Category::create(['shop_id' => $shopB->id, 'name' => 'Category B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->assertSee('Category A')
            ->assertDontSee('Category B');
    }
}
