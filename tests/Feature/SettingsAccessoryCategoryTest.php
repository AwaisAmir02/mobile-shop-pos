<?php

namespace Tests\Feature;

use App\Models\AccessoryCategoryOption;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsAccessoryCategoryTest extends TestCase
{
    use RefreshDatabase;

    protected function accessoryMainCategory(Shop $shop): MainCategory
    {
        return MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
    }

    public function test_owner_can_add_an_accessory_category_and_it_appears_on_the_add_product_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = $this->accessoryMainCategory($shop);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openAccessoryCategoryCreate')
            ->set('accessoryCategoryName', 'Tempered Glass')
            ->set('accessoryCategoryMainCategoryId', (string) $accessory->id)
            ->call('saveAccessoryCategory')
            ->assertHasNoErrors();

        $this->assertTrue(AccessoryCategoryOption::where('name', 'Tempered Glass')->where('main_category_id', $accessory->id)->exists());

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertSee('Tempered Glass');
    }

    public function test_owner_can_rename_an_accessory_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = $this->accessoryMainCategory($shop);
        $option = AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Charger']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openAccessoryCategoryEdit', $option->id)
            ->set('accessoryCategoryName', 'Fast Charger')
            ->call('saveAccessoryCategory')
            ->assertHasNoErrors();

        $this->assertSame('Fast Charger', $option->fresh()->name);
    }

    public function test_deleting_an_accessory_category_in_use_is_blocked_and_the_product_keeps_its_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = $this->accessoryMainCategory($shop);
        $option = AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Charger']);

        Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => '20W Charger',
            'price' => 1500,
            'stock_quantity' => 10,
            'details' => ['category' => 'Charger'],
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteAccessoryCategory', $option->id);

        $this->assertNotNull($option->fresh());
        $this->assertSame('Charger', Product::first()->details['category']);
    }

    public function test_deleting_an_unused_accessory_category_succeeds(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = $this->accessoryMainCategory($shop);
        $option = AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Unused Category']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteAccessoryCategory', $option->id);

        $this->assertNull($option->fresh());
    }

    public function test_accessory_categories_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $accessoryA = $this->accessoryMainCategory($shopA);
        $accessoryB = $this->accessoryMainCategory($shopB);
        AccessoryCategoryOption::create(['shop_id' => $shopA->id, 'main_category_id' => $accessoryA->id, 'name' => 'Category A']);
        AccessoryCategoryOption::create(['shop_id' => $shopB->id, 'main_category_id' => $accessoryB->id, 'name' => 'Category B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->assertSee('Category A')
            ->assertDontSee('Category B');
    }

    public function test_default_accessory_categories_are_seeded_on_first_visit_to_products(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->assertViewHas('accessoryCategoryOptions', function ($options) {
                $labels = collect($options)->pluck('label');

                return $labels->contains('Case / Cover')
                    && $labels->contains('Charger')
                    && $labels->contains('Cable')
                    && $labels->contains('Screen Protector')
                    && $labels->contains('Earphones')
                    && $labels->contains('Power Bank')
                    && $labels->contains('Other');
            });

        $this->assertSame(7, AccessoryCategoryOption::count());
    }

    public function test_a_product_can_be_created_with_a_manageable_accessory_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accessory = $this->accessoryMainCategory($shop);
        AccessoryCategoryOption::create(['shop_id' => $shop->id, 'main_category_id' => $accessory->id, 'name' => 'Tempered Glass']);

        $this->actingAs($owner);

        Livewire::test('products.index')
            ->set('type', 'accessory')
            ->set('name', 'Premium Glass Protector')
            ->set('price', '300')
            ->set('stock_quantity', '15')
            ->set('category', 'Tempered Glass')
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Premium Glass Protector')->firstOrFail();
        $this->assertSame('Tempered Glass', $product->details['category']);
    }

    public function test_a_role_without_settings_access_cannot_reach_accessory_categories(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }
}
