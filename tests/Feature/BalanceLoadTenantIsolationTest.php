<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceLoadTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_balance_loads(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        BalanceLoad::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'network' => 'Jazz',
            'amount' => 100,
        ]);

        BalanceLoad::create([
            'shop_id' => $shopB->id,
            'user_id' => $userB->id,
            'network' => 'Zong',
            'amount' => 200,
        ]);

        $this->actingAs($userA);

        Livewire::test('balance-loads.history')
            ->assertSee('Jazz')
            ->assertDontSee('Zong')
            ->assertSee('Rs 100.00');
    }

    public function test_saving_a_balance_load_auto_assigns_the_authenticated_users_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        Livewire::test('balance-loads.create')
            ->set('networkChoice', 'Jazz')
            ->set('amount', '150')
            ->call('save');

        $load = BalanceLoad::first();
        $this->assertSame($shop->id, $load->shop_id);
        $this->assertEquals(150, $load->amount);
    }
}
