<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VendorSuperAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendors_is_enabled_by_default_for_a_new_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $this->get(route('vendors.index'))->assertOk();
    }

    public function test_super_admin_can_disable_vendors_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleScreen', 'vendors');

        $this->assertTrue($shopA->fresh()->isScreenDisabled('vendors'));
        $this->assertFalse($shopB->fresh()->isScreenDisabled('vendors'));
    }

    public function test_disabling_vendors_blocks_the_screen_even_for_the_owner(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'disabled_screens' => ['vendors']]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        $this->get(route('vendors.index'))->assertForbidden();
    }

    public function test_disabling_vendors_hides_the_vendor_field_on_stock_in_but_keeps_stored_vendor_data(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);
        $entry = StockIn::create(['shop_id' => $shop->id, 'vendor_id' => $vendor->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 5, 'stock_date' => now()->toDateString(), 'payment_status' => 'paid', 'amount_paid' => 0]);

        $shop->update(['disabled_screens' => ['vendors']]);
        $this->actingAs($owner);

        $this->get(route('stock-ins.index'))->assertDontSee('for="vendorId"', escape: false);
        $this->get(route('stock-ins.history'))->assertDontSee('for="vendorFilter"', escape: false);

        $this->assertSame($vendor->id, $entry->fresh()->vendor_id);
    }

    public function test_disabling_vendors_prevents_a_vendor_id_from_being_saved_even_if_tampered_into_the_request(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'disabled_screens' => ['vendors']]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme Distributors']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);
        $this->actingAs($owner);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('vendorId', (string) $vendor->id)
            ->set('quantity', '5')
            ->set('paymentStatus', 'paid')
            ->set('stock_date', now()->toDateString())
            ->call('save');

        $this->assertDatabaseHas('stock_ins', ['product_id' => $product->id, 'vendor_id' => null]);
    }

    public function test_a_role_without_vendors_permission_cannot_reach_the_vendors_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('vendors.index'))->assertForbidden();
    }

    public function test_a_role_with_vendors_permission_can_reach_the_vendors_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Inventory Clerk', 'permissions' => ['vendors']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('vendors.index'))->assertOk();
    }

    /**
     * Pre-existing Roles (created before the Vendors screen existed) simply
     * have a permissions array that never mentions 'vendors' — there is no
     * backfill step, so a Role representing "already existed" data here
     * still denies access until the owner explicitly ticks the box.
     */
    public function test_a_pre_existing_role_is_not_automatically_granted_vendors_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['stock-ins', 'customers', 'sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('vendors.index'))->assertForbidden();
    }

    public function test_the_owner_always_has_vendors_access_regardless_of_role(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);

        $this->actingAs($owner);

        $this->get(route('vendors.index'))->assertOk();
    }
}
