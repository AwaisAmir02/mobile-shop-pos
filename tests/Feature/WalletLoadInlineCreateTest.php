<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\User;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadInlineCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_selecting_new_provider_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('provider', '__create__')
            ->assertSet('provider', '')
            ->assertDispatched('open-modal', name: 'quick-create-wallet-provider');
    }

    public function test_a_provider_created_inline_is_actually_persisted_and_auto_selected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-providers.quick-create')
            ->set('name', 'SadaPay')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(WalletProvider::where('name', 'SadaPay')->where('shop_id', $shop->id)->exists());

        Livewire::test('wallet-loads.create')
            ->call('onWalletProviderCreated', 'SadaPay')
            ->assertSet('provider', 'SadaPay');
    }

    public function test_a_provider_created_inline_is_tenant_scoped(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        $this->actingAs($ownerA);
        Livewire::test('wallet-providers.quick-create')
            ->set('name', 'Shop A Provider')
            ->call('save');

        $this->actingAs($ownerB);
        $this->assertFalse(WalletProvider::where('name', 'Shop A Provider')->exists());
    }

    public function test_a_duplicate_provider_name_within_the_same_shop_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        WalletProvider::create(['shop_id' => $shop->id, 'name' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-providers.quick-create')
            ->set('name', 'JazzCash')
            ->call('save')
            ->assertHasErrors(['name']);
    }

    public function test_selecting_new_shop_account_still_works_alongside_the_new_provider_flow(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', '__create__')
            ->assertSet('shopAccountId', '')
            ->assertDispatched('open-modal', name: 'quick-create-shop-account');
    }
}
