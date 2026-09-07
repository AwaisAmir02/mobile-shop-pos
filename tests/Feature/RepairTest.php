<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_repair_computes_the_total_as_amount_minus_discount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('mainCategorySlug', 'mobile')
            ->set('description', 'iPhone 13, screen cracked')
            ->set('amount', '3000')
            ->set('discount', '500')
            ->assertViewHas('totalCollected', 2500.0)
            ->call('save')
            ->assertHasNoErrors();

        $repair = Repair::firstOrFail();
        $this->assertSame($shop->id, $repair->shop_id);
        $this->assertSame('mobile', $repair->category);
        $this->assertSame('iPhone 13, screen cracked', $repair->description);
        $this->assertEquals(3000, $repair->amount);
        $this->assertEquals(500, $repair->discount);
        $this->assertEquals(2500, $repair->total);
    }

    public function test_the_total_never_goes_negative_when_discount_exceeds_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('description', 'Charger not powering on')
            ->set('amount', '100')
            ->set('discount', '9999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0, Repair::firstOrFail()->total);
    }

    public function test_the_accessory_category_can_be_selected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('mainCategorySlug', 'accessory')
            ->set('description', 'Samsung charger, not powering on')
            ->set('amount', '200')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('accessory', Repair::firstOrFail()->category);
    }

    public function test_a_repair_can_be_linked_to_a_customer_or_left_as_walk_in(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('customerId', (string) $customer->id)
            ->set('description', 'Screen replacement')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, Repair::firstOrFail()->customer_id);
    }

    public function test_selecting_new_customer_from_the_repairs_screen_opens_the_shared_quick_create_component(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('repairs.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    public function test_a_tampered_customer_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        $this->actingAs($ownerA);

        Livewire::test('repairs.create')
            ->set('customerId', (string) $customerB->id)
            ->set('description', 'Screen replacement')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, Repair::count());
    }

    public function test_repairs_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        Repair::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'category' => 'phone', 'description' => 'Shop A repair', 'amount' => 100, 'discount' => 0, 'total' => 100]);
        Repair::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'category' => 'phone', 'description' => 'Shop B repair', 'amount' => 200, 'discount' => 0, 'total' => 200]);

        $this->actingAs($ownerA);

        Livewire::test('repairs.history')
            ->assertSee('Shop A repair')
            ->assertDontSee('Shop B repair');

        $this->assertSame(1, Repair::count());
    }

    public function test_a_role_without_repairs_access_cannot_reach_repair_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('repairs.index'))->assertForbidden();
        $this->get(route('repairs.history'))->assertForbidden();
    }

    public function test_a_role_with_repairs_access_can_reach_repair_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Technician', 'permissions' => ['repairs']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('repairs.index'))->assertOk();
        $this->get(route('repairs.history'))->assertOk();
    }

    public function test_super_admin_can_disable_the_repairs_module_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);
        $this->get(route('repairs.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'repairs');

        $this->assertTrue($shop->fresh()->isScreenDisabled('repairs'));

        $this->actingAs($owner->fresh());
        $this->get(route('repairs.index'))->assertForbidden();
    }

    public function test_repairs_revenue_appears_as_its_own_line_in_reports(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'phone', 'description' => 'X', 'amount' => 1500, 'discount' => 0, 'total' => 1500]);

        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('day', $today)
            ->assertViewHas('totalRepairsRevenue', 1500.0)
            ->assertViewHas('totalRevenue', 0.0);
    }
}
