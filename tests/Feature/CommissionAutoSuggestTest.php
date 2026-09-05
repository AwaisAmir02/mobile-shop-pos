<?php

namespace Tests\Feature;

use App\Models\Network;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A commission % set in Settings auto-suggests the fee field on each
 * module's create screen from amount × percent — but must never fight a
 * fee the user has already typed themselves, the same "don't fight the
 * manual field" requirement the Wallet Load fee field has always had.
 */
class CommissionAutoSuggestTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_load_fee_auto_suggests_from_commission_percent_and_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'wallet_load_commission_percent' => 5]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('amount', '1000')
            ->assertSet('fee', '50.00');
    }

    public function test_wallet_load_fee_auto_suggest_does_not_override_a_manually_edited_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'wallet_load_commission_percent' => 5]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('amount', '1000')
            ->assertSet('fee', '50.00')
            ->set('fee', '75')
            ->set('amount', '2000')
            ->assertSet('fee', '75');
    }

    public function test_wallet_load_fee_stays_zero_when_no_commission_percent_is_configured(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('amount', '1000')
            ->assertSet('fee', '0');
    }

    public function test_bills_fee_auto_suggests_from_commission_percent_and_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'bills_commission_percent' => 3]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('amount', '2000')
            ->assertSet('fee', '60.00');
    }

    public function test_bills_fee_auto_suggest_does_not_override_a_manually_edited_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'bills_commission_percent' => 3]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('amount', '2000')
            ->assertSet('fee', '60.00')
            ->set('fee', '20')
            ->set('amount', '5000')
            ->assertSet('fee', '20');
    }

    public function test_sim_sale_fee_auto_suggests_from_commission_percent_and_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'sim_sale_commission_percent' => 10]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Network::create(['shop_id' => $shop->id, 'name' => 'Jazz', 'color' => '#ff0000']);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('amount', '150')
            ->assertSet('fee', '15.00');
    }

    public function test_sim_sale_fee_auto_suggest_does_not_override_a_manually_edited_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'sim_sale_commission_percent' => 10]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Network::create(['shop_id' => $shop->id, 'name' => 'Jazz', 'color' => '#ff0000']);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('amount', '150')
            ->assertSet('fee', '15.00')
            ->set('fee', '5')
            ->set('amount', '300')
            ->assertSet('fee', '5');
    }

    public function test_balance_load_fee_auto_suggests_from_commission_percent_and_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'balance_load_commission_percent' => 2]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Network::create(['shop_id' => $shop->id, 'name' => 'Jazz', 'color' => '#ff0000']);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '500')
            ->assertSet('fee', '10.00');
    }

    public function test_balance_load_fee_auto_suggest_does_not_override_a_manually_edited_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'balance_load_commission_percent' => 2]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        Network::create(['shop_id' => $shop->id, 'name' => 'Jazz', 'color' => '#ff0000']);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '500')
            ->assertSet('fee', '10.00')
            ->set('fee', '3')
            ->set('amount', '1000')
            ->assertSet('fee', '3');
    }

    public function test_nadra_verification_fee_auto_suggests_from_commission_percent_and_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'nadra_verification_commission_percent' => 20]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('amount', '100')
            ->assertSet('fee', '20.00');
    }

    public function test_nadra_verification_fee_auto_suggest_does_not_override_a_manually_edited_fee(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'nadra_verification_commission_percent' => 20]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('amount', '100')
            ->assertSet('fee', '20.00')
            ->set('fee', '5')
            ->set('amount', '200')
            ->assertSet('fee', '5');
    }
}
