<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsWalletProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_wallet_provider_and_it_appears_on_the_wallet_load_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('name', 'SadaPay')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(WalletProvider::where('name', 'SadaPay')->exists());

        Livewire::test('wallet-loads.create')->assertSee('SadaPay');
    }

    public function test_owner_can_rename_a_wallet_provider(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $provider = WalletProvider::create(['shop_id' => $shop->id, 'name' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('openEdit', $provider->id)
            ->set('name', 'JazzCash Wallet')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('JazzCash Wallet', $provider->fresh()->name);
    }

    public function test_deleting_a_wallet_provider_in_use_is_blocked_and_history_stays_intact(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $provider = WalletProvider::create(['shop_id' => $shop->id, 'name' => 'JazzCash']);

        WalletLoad::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'provider' => 'JazzCash',
            'account_number' => '03001234567',
            'amount' => 500,
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('delete', $provider->id);

        $this->assertNotNull($provider->fresh());
        $this->assertSame('JazzCash', WalletLoad::first()->provider);
    }

    public function test_deleting_an_unused_wallet_provider_succeeds(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $provider = WalletProvider::create(['shop_id' => $shop->id, 'name' => 'Unused Wallet']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('delete', $provider->id);

        $this->assertNull($provider->fresh());
    }

    public function test_wallet_providers_are_scoped_per_shop_in_settings(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        WalletProvider::create(['shop_id' => $shopA->id, 'name' => 'Provider A']);
        WalletProvider::create(['shop_id' => $shopB->id, 'name' => 'Provider B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->assertSee('Provider A')
            ->assertDontSee('Provider B');
    }

    public function test_a_role_without_settings_access_cannot_reach_settings(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }

    public function test_a_role_with_settings_access_can_reach_settings(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['settings']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertOk();
    }
}
