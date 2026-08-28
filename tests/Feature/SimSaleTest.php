<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SimSaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_sim_sale_computes_the_total_as_amount_minus_discount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('simType', 'postpaid')
            ->set('simForm', 'esim')
            ->set('simNumber', '03001234567')
            ->set('amount', '500')
            ->set('discount', '50')
            ->assertViewHas('totalCollected', 450.0)
            ->call('save')
            ->assertHasNoErrors();

        $sale = SimSale::firstOrFail();
        $this->assertSame($shop->id, $sale->shop_id);
        $this->assertSame('postpaid', $sale->sim_type->value);
        $this->assertSame('esim', $sale->sim_form->value);
        $this->assertEquals(500, $sale->amount);
        $this->assertEquals(50, $sale->discount);
        $this->assertEquals(450, $sale->total);
        $this->assertFalse($sale->is_duplicate);
        $this->assertNull($sale->customer_id);
    }

    public function test_the_total_never_goes_negative_when_discount_exceeds_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('simNumber', '03001234567')
            ->set('amount', '100')
            ->set('discount', '9999')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(0, SimSale::firstOrFail()->total);
    }

    public function test_the_duplicate_sim_flag_is_recorded(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('isDuplicate', true)
            ->set('simNumber', '03001234567')
            ->set('amount', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(SimSale::firstOrFail()->is_duplicate);
    }

    public function test_a_sim_sale_can_be_linked_to_a_customer_or_left_as_walk_in(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('customerId', (string) $customer->id)
            ->set('simNumber', '03001234567')
            ->set('amount', '100')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, SimSale::firstOrFail()->customer_id);
    }

    public function test_selecting_new_customer_from_the_sim_sale_screen_opens_the_shared_quick_create_component(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
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

        Livewire::test('sim-sales.create')
            ->set('customerId', (string) $customerB->id)
            ->set('simNumber', '03001234567')
            ->set('amount', '100')
            ->call('save')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, SimSale::count());
    }

    public function test_sim_sales_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        SimSale::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => 'A-1', 'amount' => 100, 'discount' => 0, 'total' => 100]);
        SimSale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'network' => 'Zong', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => 'B-1', 'amount' => 200, 'discount' => 0, 'total' => 200]);

        $this->actingAs($ownerA);

        Livewire::test('sim-sales.history')
            ->assertSee('A-1')
            ->assertDontSee('B-1');

        $this->assertSame(1, SimSale::count());
    }

    public function test_a_role_without_sim_sales_access_cannot_reach_sim_sale_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('sim-sales.index'))->assertForbidden();
        $this->get(route('sim-sales.history'))->assertForbidden();
    }

    public function test_a_role_with_sim_sales_access_can_reach_sim_sale_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'SIM Agent', 'permissions' => ['sim-sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('sim-sales.index'))->assertOk();
        $this->get(route('sim-sales.history'))->assertOk();
    }

    public function test_super_admin_can_disable_the_sim_sale_module_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);
        $this->get(route('sim-sales.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'sim-sales');

        $this->assertTrue($shop->fresh()->isScreenDisabled('sim-sales'));

        $this->actingAs($owner->fresh());
        $this->get(route('sim-sales.index'))->assertForbidden();
    }

    public function test_sim_sale_revenue_appears_as_its_own_line_in_reports_separate_from_regular_sales(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => 'X', 'amount' => 300, 'discount' => 0, 'total' => 300]);

        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('day', $today)
            ->assertViewHas('totalSimSaleRevenue', 300.0)
            ->assertViewHas('totalSimSalesSold', 1)
            ->assertViewHas('totalRevenue', 0.0);
    }
}
