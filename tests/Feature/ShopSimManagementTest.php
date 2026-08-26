<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopSim;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopSimManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_sim(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-management.index')
            ->set('number', '03001234567')
            ->set('networkChoice', 'Jazz')
            ->call('save')
            ->assertHasNoErrors();

        $sim = ShopSim::first();
        $this->assertSame($shop->id, $sim->shop_id);
        $this->assertSame('03001234567', $sim->number);
        $this->assertSame('Jazz', $sim->network);
        $this->assertTrue($sim->is_active);
    }

    public function test_owner_can_edit_and_deactivate_a_sim(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $sim = ShopSim::create(['shop_id' => $shop->id, 'number' => '03001234567', 'network' => 'Jazz', 'is_active' => true]);

        $this->actingAs($owner);

        Livewire::test('sim-management.index')
            ->call('openEdit', $sim->id)
            ->set('is_active', false)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertFalse($sim->fresh()->is_active);
    }

    public function test_a_shop_only_sees_its_own_sims(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        ShopSim::create(['shop_id' => $shopA->id, 'number' => '03001111111', 'network' => 'Jazz']);
        ShopSim::create(['shop_id' => $shopB->id, 'number' => '03002222222', 'network' => 'Zong']);

        $this->actingAs($ownerA);

        Livewire::test('sim-management.index')
            ->assertSee('03001111111')
            ->assertDontSee('03002222222');
    }

    public function test_a_role_without_sim_management_access_cannot_reach_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('sim-management.index'))->assertForbidden();
    }
}
