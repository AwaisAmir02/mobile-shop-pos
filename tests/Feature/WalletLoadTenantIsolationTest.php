<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_shop_only_sees_its_own_wallet_loads(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        WalletLoad::create([
            'shop_id' => $shopA->id,
            'user_id' => $userA->id,
            'provider' => 'JazzCash',
            'account_number' => '03001234567',
            'amount' => 500,
        ]);

        WalletLoad::create([
            'shop_id' => $shopB->id,
            'user_id' => $userB->id,
            'provider' => 'Easypaisa',
            'account_number' => '03007654321',
            'amount' => 900,
        ]);

        $this->actingAs($userA);

        Livewire::test('wallet-loads.history')
            ->assertSee('JazzCash')
            ->assertDontSee('Easypaisa')
            ->assertSee('Rs 500.00');
    }

    public function test_saving_a_wallet_load_auto_assigns_the_authenticated_users_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        Livewire::test('wallet-loads.create')
            ->set('provider', 'NayaPay')
            ->set('accountNumber', '03001234567')
            ->set('amount', '250')
            ->call('save');

        $load = WalletLoad::first();
        $this->assertSame($shop->id, $load->shop_id);
        $this->assertSame('NayaPay', $load->provider);
        $this->assertEquals(250, $load->amount);
    }

    public function test_wallet_providers_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $userA = User::factory()->create(['shop_id' => $shopA->id]);
        $userB = User::factory()->create(['shop_id' => $shopB->id]);

        $this->actingAs($userA);
        WalletProvider::ensureDefaultsExist();

        $this->actingAs($userB);
        WalletProvider::create(['name' => 'CustomWallet']);

        $this->assertSame(1, WalletProvider::count());

        $this->actingAs($userA);
        $this->assertSame(3, WalletProvider::count());
        $this->assertFalse(WalletProvider::where('name', 'CustomWallet')->exists());
    }

    public function test_default_wallet_providers_are_seeded_on_first_visit(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $user = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($user);

        Livewire::test('wallet-loads.create')
            ->assertSee('JazzCash')
            ->assertSee('Easypaisa')
            ->assertSee('NayaPay');

        $this->assertSame(3, WalletProvider::count());
    }
}
