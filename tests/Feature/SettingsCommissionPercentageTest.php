<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsCommissionPercentageTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_set_commission_percentages_for_every_listed_module(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'percentage')
            ->set('simSaleCommissionPercent', '5')
            ->set('balanceLoadCommissionPercent', '2.5')
            ->set('walletLoadCommissionPercent', '1.5')
            ->set('billsCommissionPercent', '3')
            ->set('nadraVerificationCommissionPercent', '10')
            ->call('saveCommissionPercentages')
            ->assertHasNoErrors();

        $shop->refresh();
        $this->assertEquals(5, $shop->sim_sale_commission_percent);
        $this->assertEquals(2.5, $shop->balance_load_commission_percent);
        $this->assertEquals(1.5, $shop->wallet_load_commission_percent);
        $this->assertEquals(3, $shop->bills_commission_percent);
        $this->assertEquals(10, $shop->nadra_verification_commission_percent);
    }

    public function test_leaving_a_percentage_blank_clears_it_to_null(): void
    {
        $shop = Shop::create(['name' => 'Shop A', 'sim_sale_commission_percent' => 5]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'percentage')
            ->set('simSaleCommissionPercent', '')
            ->call('saveCommissionPercentages')
            ->assertHasNoErrors();

        $this->assertNull($shop->fresh()->sim_sale_commission_percent);
    }

    public function test_a_percentage_over_100_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'percentage')
            ->set('simSaleCommissionPercent', '150')
            ->call('saveCommissionPercentages')
            ->assertHasErrors(['simSaleCommissionPercent']);

        $this->assertNull($shop->fresh()->sim_sale_commission_percent);
    }

    public function test_udhaar_has_no_commission_percentage_field(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'percentage')
            ->assertSee('SIM Sale %')
            ->assertSee('Balance Load %')
            ->assertSee('Wallet Load %')
            ->assertSee('Bills %')
            ->assertSee('NADRA Verification %')
            ->assertDontSee('Udhaar %')
            ->assertSee("Udhaar isn't listed here", false);
    }

    public function test_percentages_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A', 'sim_sale_commission_percent' => 5]);
        $shopB = Shop::create(['name' => 'Shop B', 'sim_sale_commission_percent' => 9]);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->set('tab', 'percentage')
            ->assertSet('simSaleCommissionPercent', '5.00');

        $this->assertEquals(9, $shopB->fresh()->sim_sale_commission_percent);
    }

    public function test_a_role_without_settings_access_cannot_save_commission_percentages(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('settings.index'))->assertForbidden();
    }
}
