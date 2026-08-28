<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopAccount;
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
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($user);

        Livewire::test('wallet-loads.create')
            ->set('provider', 'NayaPay')
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '250')
            ->set('fee', '10')
            ->set('discount', '5')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::first();
        $this->assertSame($shop->id, $load->shop_id);
        $this->assertSame('NayaPay', $load->provider);
        $this->assertSame('Ali Khan', $load->account_name);
        $this->assertSame($account->id, $load->shop_account_id);
        $this->assertEquals(250, $load->amount);
        $this->assertEquals(10, $load->fee);
        $this->assertEquals(5, $load->discount);
        $this->assertEquals(255, $load->total);
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
