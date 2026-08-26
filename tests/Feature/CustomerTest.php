<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_customers(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        Customer::create(['shop_id' => $shopA->id, 'name' => 'Ali Khan', 'phone' => '03001111111']);
        Customer::create(['shop_id' => $shopB->id, 'name' => 'Bilal Ahmed', 'phone' => '03002222222']);

        $this->actingAs($ownerA);

        Livewire::test('customers.index')
            ->assertSee('Ali Khan')
            ->assertDontSee('Bilal Ahmed');
    }

    public function test_searching_by_phone_never_leaks_another_shops_customer(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        Customer::create(['shop_id' => $shopB->id, 'name' => 'Bilal Ahmed', 'phone' => '03009999999']);

        $this->actingAs($ownerA);

        Livewire::test('customers.index')
            ->set('search', '03009999999')
            ->assertDontSee('Bilal Ahmed');
    }

    public function test_creating_a_customer_auto_assigns_the_authenticated_users_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('customers.index')
            ->set('name', 'Sana Malik')
            ->set('phone', '03005551234')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Sana Malik')->firstOrFail();
        $this->assertSame($shop->id, $customer->shop_id);
        $this->assertSame('03005551234', $customer->phone);
    }

    public function test_owner_can_edit_a_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana Malik']);

        $this->actingAs($owner);

        Livewire::test('customers.index')
            ->call('openEdit', $customer->id)
            ->set('phone', '03001231234')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('03001231234', $customer->fresh()->phone);
    }

    public function test_a_customer_with_no_financial_history_can_be_deleted(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana Malik']);

        $this->actingAs($owner);

        Livewire::test('customers.index')->call('delete', $customer->id);

        $this->assertNull($customer->fresh());
    }

    public function test_a_role_without_customers_access_cannot_reach_customers(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('customers.index'))->assertForbidden();
    }

    public function test_a_role_with_customers_access_can_reach_customers(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['customers']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('customers.index'))->assertOk();
    }
}
