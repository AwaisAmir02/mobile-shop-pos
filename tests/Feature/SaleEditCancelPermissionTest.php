<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SaleEditCancelPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function shopWithSale(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'A', 'price' => 100, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_id' => $product->id, 'product_name' => 'A', 'product_type' => 'accessory', 'unit_price' => 100, 'list_price' => 100, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 100]);

        return [$shop, $owner, $sale];
    }

    public function test_the_owner_always_has_edit_and_cancel_access(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $this->actingAs($owner);

        $this->get(route('sales.edit', $sale))->assertOk();

        Livewire::test('sales.show', ['sale' => $sale])
            ->call('openCancel')
            ->assertDispatched('open-modal', name: 'cancel-sale-form');
    }

    public function test_a_role_without_the_permission_cannot_edit_or_cancel(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('sales.edit', $sale))->assertForbidden();

        Livewire::test('sales.show', ['sale' => $sale])
            ->call('openCancel')
            ->assertNotDispatched('open-modal');

        $this->assertFalse($sale->fresh()->isCancelled());
    }

    public function test_a_role_with_the_permission_can_edit_and_cancel(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Senior Cashier', 'permissions' => ['sales', 'sales-edit-cancel']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('sales.edit', $sale))->assertOk();

        Livewire::test('sales.show', ['sale' => $sale])
            ->set('cancelReason', 'test')
            ->call('cancelSale');

        $this->assertTrue($sale->fresh()->isCancelled());
    }

    /**
     * Pre-existing Roles simply have a permissions array that never
     * mentions 'sales-edit-cancel' — there is no backfill step, matching
     * the same precedent established for Vendors.
     */
    public function test_a_pre_existing_role_is_not_automatically_granted_the_permission(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Old Role', 'permissions' => ['sales', 'stock-ins', 'customers']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('sales.edit', $sale))->assertForbidden();
    }

    public function test_super_admin_can_disable_sales_edit_cancel_for_one_shop_without_affecting_another(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleScreen', 'sales-edit-cancel');

        $this->assertTrue($shopA->fresh()->isScreenDisabled('sales-edit-cancel'));
        $this->assertFalse($shopB->fresh()->isScreenDisabled('sales-edit-cancel'));
    }

    public function test_super_admin_disabling_sales_edit_cancel_blocks_even_the_owner(): void
    {
        [$shop, $owner, $sale] = $this->shopWithSale();
        $shop->update(['disabled_screens' => ['sales-edit-cancel']]);
        $this->actingAs($owner);

        $this->get(route('sales.edit', $sale))->assertForbidden();
    }
}
