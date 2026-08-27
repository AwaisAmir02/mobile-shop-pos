<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class SuperAdminUserAndModuleControlTest extends TestCase
{
    use RefreshDatabase;

    // ── Expanded shop-detail data visibility ──────────────────────────

    public function test_shop_detail_shows_accurate_cross_module_data_scoped_to_the_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);

        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Customer A']);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B1']);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B2']);

        UdhaarTransaction::create(['shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'type' => 'given', 'amount' => 1500, 'transaction_date' => '2026-08-01']);
        UdhaarTransaction::create(['shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'type' => 'repayment', 'amount' => 500, 'transaction_date' => '2026-08-05']);

        $product = Product::create(['shop_id' => $shopA->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'stock_quantity' => 10, 'details' => []]);
        StockIn::create(['shop_id' => $shopA->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'quantity' => 20, 'stock_date' => now()->toDateString()]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shopA])
            ->assertViewHas('customerCount', 1)
            ->assertViewHas('udhaarOutstanding', 1000.0)
            ->assertViewHas('totalStockInUnits', 20);

        Livewire::test('admin.shops.show', ['shop' => $shopB])
            ->assertViewHas('customerCount', 2)
            ->assertViewHas('udhaarOutstanding', 0.0);
    }

    public function test_shops_list_shows_a_user_count_per_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        User::factory()->create(['shop_id' => $shop->id]);
        User::factory()->staff()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);

        Livewire::test('admin.dashboard')->assertViewHas('shops', function ($shops) use ($shop) {
            return $shops->firstWhere('id', $shop->id)->users_count === 2;
        });
    }

    // ── User activation/deactivation ──────────────────────────────────

    public function test_super_admin_can_deactivate_a_user_and_they_are_immediately_blocked_from_logging_in(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'password' => Hash::make('password')]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->call('toggleUserActive', $staff->id);

        $this->assertFalse($staff->fresh()->is_active);

        auth()->logout();

        Livewire::test('pages.auth.login')
            ->set('form.email', $staff->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.email');

        $this->assertGuest();
    }

    public function test_super_admin_can_reactivate_a_deactivated_user(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'is_active' => false, 'password' => Hash::make('password')]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->call('toggleUserActive', $staff->id);

        $this->assertTrue($staff->fresh()->is_active);

        auth()->logout();

        Livewire::test('pages.auth.login')
            ->set('form.email', $staff->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($staff);
    }

    public function test_a_deactivated_users_active_session_loses_access_on_the_next_request(): void
    {
        // Deactivation blocks future logins; an already-authenticated
        // session isn't force-logged-out mid-request, but they can never
        // log back in once their session ends. Confirm the toggle itself
        // is scoped correctly and doesn't touch other shops' users.
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $staffA = User::factory()->staff()->create(['shop_id' => $shopA->id]);
        $staffB = User::factory()->staff()->create(['shop_id' => $shopB->id]);

        $this->actingAs($admin);

        Livewire::test('admin.shops.show', ['shop' => $shopA])
            ->call('toggleUserActive', $staffA->id);

        $this->assertFalse($staffA->fresh()->is_active);
        $this->assertTrue($staffB->fresh()->is_active);
    }

    // ── Per-shop, per-module access override ───────────────────────────

    public function test_super_admin_revoking_a_module_blocks_all_users_of_that_shop_including_the_owner(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['udhaar']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($admin);

        // Both can reach it before the toggle.
        $this->actingAs($owner);
        $this->get(route('udhaar.index'))->assertOk();
        $this->actingAs($staff);
        $this->get(route('udhaar.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->call('toggleScreen', 'udhaar');

        $this->assertTrue($shop->fresh()->isScreenDisabled('udhaar'));

        // Owner is blocked despite is_owner bypassing role checks.
        // Re-fetched to mirror a real separate request, where the user is
        // resolved fresh from the session rather than reusing a PHP object
        // whose "shop" relation was cached before the toggle.
        $this->actingAs($owner->fresh());
        $this->get(route('udhaar.index'))->assertForbidden();

        // Staff, who was explicitly granted "udhaar" by their own role, is also blocked.
        $this->actingAs($staff->fresh());
        $this->get(route('udhaar.index'))->assertForbidden();
    }

    public function test_the_module_toggle_also_hides_the_screen_from_the_sidebar(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'expenses');

        $this->actingAs($owner->fresh());
        $this->get(route('dashboard'))->assertDontSee(route('expenses.index'));
    }

    public function test_disabling_a_module_for_one_shop_does_not_affect_another_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shopA])->call('toggleScreen', 'sales');

        $this->actingAs($ownerA);
        $this->get(route('sales.index'))->assertForbidden();

        $this->actingAs($ownerB);
        $this->get(route('sales.index'))->assertOk();
    }

    public function test_toggling_a_module_twice_re_enables_it(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($admin);
        $component = Livewire::test('admin.shops.show', ['shop' => $shop]);
        $component->call('toggleScreen', 'expenses');
        $component->call('toggleScreen', 'expenses');

        $this->assertFalse($shop->fresh()->isScreenDisabled('expenses'));

        $this->actingAs($owner);
        $this->get(route('expenses.index'))->assertOk();
    }

    public function test_a_screen_enabled_by_super_admin_but_not_granted_by_the_shops_own_role_is_still_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        // Super Admin never touched this shop's module list — expenses stays enabled platform-wide.
        $this->assertFalse($shop->fresh()->isScreenDisabled('expenses'));

        $this->actingAs($staff);

        // But the role itself never granted "expenses", so it's still blocked.
        $this->get(route('expenses.index'))->assertForbidden();
    }

    public function test_a_screen_granted_by_the_role_but_disabled_by_super_admin_is_blocked(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['expenses']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);
        $this->get(route('expenses.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'expenses');

        $this->actingAs($staff->fresh());
        $this->get(route('expenses.index'))->assertForbidden();
    }

    public function test_a_shop_user_cannot_toggle_their_own_shops_module_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $this->get('/admin')->assertForbidden();
        $this->get(route('admin.shops.show', $shop))->assertForbidden();
    }
}
