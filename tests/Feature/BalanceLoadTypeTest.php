<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceLoadTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_balance_load_defaults_to_the_balance_type_and_can_be_saved_as_package(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->assertSet('loadType', 'balance')
            ->set('networkChoice', 'Jazz')
            ->set('loadType', 'package')
            ->set('amount', '500')
            ->call('save')
            ->assertHasNoErrors();

        $load = BalanceLoad::firstOrFail();
        $this->assertSame('package', $load->load_type->value);
        $this->assertSame($shop->id, $load->shop_id);
    }

    public function test_the_type_is_displayed_on_the_history_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        BalanceLoad::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'network' => 'Jazz',
            'load_type' => 'package',
            'amount' => 300,
            'total' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.history')
            ->assertSee('Package');
    }

    public function test_the_type_is_tenant_scoped_and_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        BalanceLoad::create([
            'shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'network' => 'Jazz',
            'load_type' => 'balance', 'amount' => 100, 'total' => 100,
        ]);

        BalanceLoad::create([
            'shop_id' => $shopB->id, 'network' => 'Zong',
            'load_type' => 'package', 'amount' => 200, 'total' => 200,
        ]);

        $this->actingAs($ownerA);

        Livewire::test('balance-loads.history')
            ->assertSee('Balance')
            ->assertDontSee('Package');
    }
}
