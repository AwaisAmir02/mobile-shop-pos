<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Network;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsNetworkTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_network_and_it_appears_on_the_balance_load_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('networkName', 'Warid')
            ->call('saveNetwork')
            ->assertHasNoErrors();

        $this->assertTrue(Network::where('name', 'Warid')->exists());

        Livewire::test('balance-loads.create')->assertSee('Warid');
    }

    public function test_owner_can_rename_a_network(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $network = Network::create(['shop_id' => $shop->id, 'name' => 'Jazz']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openNetworkEdit', $network->id)
            ->set('networkName', 'Jazz 4G')
            ->call('saveNetwork')
            ->assertHasNoErrors();

        $this->assertSame('Jazz 4G', $network->fresh()->name);
    }

    public function test_deleting_a_network_in_use_is_blocked_and_history_stays_intact(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $network = Network::create(['shop_id' => $shop->id, 'name' => 'Jazz']);

        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'amount' => 500]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteNetwork', $network->id);

        $this->assertNotNull($network->fresh());
        $this->assertSame('Jazz', BalanceLoad::first()->network);
    }

    public function test_deleting_an_unused_network_succeeds(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $network = Network::create(['shop_id' => $shop->id, 'name' => 'Unused Network']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteNetwork', $network->id);

        $this->assertNull($network->fresh());
    }

    public function test_networks_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        Network::create(['shop_id' => $shopA->id, 'name' => 'Network A']);
        Network::create(['shop_id' => $shopB->id, 'name' => 'Network B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->assertSee('Network A')
            ->assertDontSee('Network B');
    }

    public function test_default_networks_are_seeded_on_first_visit_to_balance_load(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->assertSee('Jazz')
            ->assertSee('Zong')
            ->assertSee('Telenor')
            ->assertSee('Ufone');

        $this->assertSame(4, Network::count());
    }

    public function test_a_role_without_settings_access_cannot_reach_networks(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }
}
