<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsBrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_brand_and_it_appears_on_the_add_product_dropdown(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('brandName', 'Samsung')
            ->call('saveBrand')
            ->assertHasNoErrors();

        $this->assertTrue(Brand::where('name', 'Samsung')->exists());

        Livewire::test('products.index')->assertSee('Samsung');
    }

    public function test_owner_can_rename_a_brand(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsng']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openBrandEdit', $brand->id)
            ->set('brandName', 'Samsung')
            ->call('saveBrand')
            ->assertHasNoErrors();

        $this->assertSame('Samsung', $brand->fresh()->name);
    }

    public function test_deleting_a_brand_in_use_is_blocked_and_the_product_keeps_its_brand(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Samsung']);

        Product::create([
            'shop_id' => $shop->id, 'type' => 'mobile', 'name' => 'Galaxy A15',
            'price' => 25000, 'stock_quantity' => 1,
            'details' => ['brand' => 'Samsung', 'model' => null, 'imei' => null],
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteBrand', $brand->id);

        $this->assertNotNull($brand->fresh());
        $this->assertSame('Samsung', Product::first()->details['brand']);
    }

    public function test_deleting_an_unused_brand_succeeds(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $brand = Brand::create(['shop_id' => $shop->id, 'name' => 'Unused Brand']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteBrand', $brand->id);

        $this->assertNull($brand->fresh());
    }

    public function test_brands_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        Brand::create(['shop_id' => $shopA->id, 'name' => 'Brand A']);
        Brand::create(['shop_id' => $shopB->id, 'name' => 'Brand B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->set('tab', 'brands')
            ->assertSee('Brand A')
            ->assertDontSee('Brand B');
    }

    // ── Permission gating (Super Admin toggle + Role permission) ──────

    public function test_a_role_without_the_settings_brands_permission_does_not_see_the_brands_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => ! in_array('brands', $tabs, true));
    }

    public function test_a_role_granted_the_settings_brands_permission_sees_the_brands_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings-brands']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => in_array('brands', $tabs, true));
    }

    public function test_super_admin_can_disable_the_brands_tab_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'settings-brands');

        $this->assertTrue($shop->fresh()->isScreenDisabled('settings-brands'));

        $this->actingAs($owner->fresh());
        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => ! in_array('brands', $tabs, true));
    }

    public function test_a_settings_brands_permission_granted_by_the_role_but_disabled_by_super_admin_is_blocked(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings-brands']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'settings-brands');

        $this->actingAs($staff->fresh());
        Livewire::test('settings.index')
            ->assertViewHas('accessibleTabs', fn ($tabs) => ! in_array('brands', $tabs, true));
    }

    public function test_the_module_access_grid_shows_the_settings_brands_screen(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSee('Settings: Brands');
    }

    public function test_a_role_without_settings_access_cannot_reach_the_settings_route_via_brands_alone(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }

    public function test_a_role_granted_only_settings_brands_can_reach_the_settings_route(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings-brands']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertOk();
    }
}
