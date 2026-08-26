<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RolesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_a_role_with_limited_screen_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('roles.index')
            ->set('name', 'Cashier')
            ->set('permissions', ['sales', 'products'])
            ->call('save')
            ->assertHasNoErrors();

        $role = Role::where('name', 'Cashier')->firstOrFail();
        $this->assertSame($shop->id, $role->shop_id);
        $this->assertTrue($role->hasAccess('sales'));
        $this->assertTrue($role->hasAccess('products'));
        $this->assertFalse($role->hasAccess('expenses'));
    }

    public function test_owner_can_create_a_staff_user_with_a_role(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);

        $this->actingAs($owner);

        Livewire::test('users.index')
            ->set('name', 'Staff Member')
            ->set('email', 'staff@example.com')
            ->set('roleId', $role->id)
            ->call('save')
            ->assertHasNoErrors();

        $staff = User::where('email', 'staff@example.com')->firstOrFail();
        $this->assertSame($shop->id, $staff->shop_id);
        $this->assertSame($role->id, $staff->role_id);
        $this->assertFalse($staff->is_owner);
    }

    public function test_a_limited_role_user_cannot_reach_a_restricted_screen_by_url_or_see_it_in_the_sidebar(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        // Allowed screen renders fine.
        $this->get(route('sales.index'))->assertOk();

        // Sidebar only shows the permitted screen, not others.
        $this->get(route('sales.index'))
            ->assertSee('Sales')
            ->assertDontSee('Expenses')
            ->assertDontSee('Team');

        // Restricted screens are blocked at the route level even by direct URL.
        $this->get(route('expenses.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
    }

    public function test_a_user_with_no_role_has_no_screen_access(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id]);

        $this->actingAs($staff);

        $this->get(route('sales.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertForbidden();
    }

    public function test_the_original_shop_owner_always_has_full_access_regardless_of_role(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('products.index'))->assertOk();
        $this->get(route('sales.index'))->assertOk();
        $this->get(route('balance-loads.index'))->assertOk();
        $this->get(route('wallet-loads.index'))->assertOk();
        $this->get(route('expenses.index'))->assertOk();
        $this->get(route('reports.index'))->assertOk();
        $this->get(route('users.index'))->assertOk();
        $this->get(route('roles.index'))->assertOk();
    }

    public function test_a_role_in_use_cannot_be_deleted(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($owner);

        Livewire::test('roles.index')->call('delete', $role->id);

        $this->assertNotNull($role->fresh());
    }

    public function test_the_owner_account_cannot_be_deleted_or_edited_away_from_owner(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $otherOwner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($otherOwner);

        Livewire::test('users.index')->call('delete', $owner->id);

        $this->assertNotNull($owner->fresh());
        $this->assertTrue($owner->fresh()->is_owner);
    }

    public function test_roles_and_users_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        Role::create(['shop_id' => $shopA->id, 'name' => 'Cashier A', 'permissions' => ['sales']]);
        Role::create(['shop_id' => $shopB->id, 'name' => 'Cashier B', 'permissions' => ['sales']]);

        $this->actingAs($ownerA);

        Livewire::test('roles.index')
            ->assertSee('Cashier A')
            ->assertDontSee('Cashier B');

        Livewire::test('users.index')
            ->assertSee($ownerA->name)
            ->assertDontSee($ownerB->name);
    }
}
