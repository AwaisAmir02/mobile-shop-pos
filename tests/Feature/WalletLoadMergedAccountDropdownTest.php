<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wallet Provider was fully removed and absorbed into Shop Account — the
 * create screen now shows one merged dropdown ("Send From" / "Received
 * Into") instead of two, and WalletLoad.provider is auto-derived from the
 * selected account's provider_type rather than separately picked. These
 * tests confirm the derivation itself, that the old dropdown/tab/model are
 * genuinely gone, and that everything downstream that reads `provider`
 * (receipts, history, the provider-breakdown stat cards) still works
 * unmodified for both newly-created and pre-existing historical rows.
 */
class WalletLoadMergedAccountDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_derives_provider_from_the_selected_accounts_provider_type(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My Easypaisa', 'provider_type' => 'Easypaisa']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', (string) $account->id)
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Easypaisa', WalletLoad::firstOrFail()->provider);
    }

    public function test_switching_the_selected_account_changes_the_derived_provider(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $bankAccount = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Awais', 'provider_type' => 'Bank']);
        $cashAccount = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Daraz', 'provider_type' => 'Cash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', (string) $bankAccount->id)
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('amount', '500')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Bank', WalletLoad::firstOrFail()->provider);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', (string) $cashAccount->id)
            ->set('accountName', 'Bilal')
            ->set('accountNumber', '03007654321')
            ->set('amount', '300')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Cash', WalletLoad::where('account_name', 'Bilal')->firstOrFail()->provider);
    }

    public function test_derivation_applies_identically_on_the_cash_out_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->call('switchDirection', 'cash_out')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('amountReceivedNow', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertSame('cash_out', $load->direction->value);
        $this->assertSame('JazzCash', $load->provider);
    }

    public function test_there_is_no_separate_wallet_provider_field_on_the_create_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->assertDontSee('Wallet Provider')
            ->assertDontSee('Select a provider');
    }

    public function test_the_settings_wallet_providers_tab_no_longer_exists(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->call('setTab', 'wallet-providers')
            ->assertSet('tab', 'accessory-categories');

        Livewire::test('settings.index')
            ->assertDontSee('Wallet Providers');
    }

    public function test_the_wallet_provider_model_and_table_are_gone(): void
    {
        $this->assertFalse(class_exists(WalletProvider::class));
        $this->assertFalse(Schema::hasTable('wallet_providers'));
    }

    // ── Downstream behavior stays intact for historical rows ─────────

    public function test_the_provider_breakdown_stat_cards_still_work_for_a_newly_created_record(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', (string) $account->id)
            ->set('accountName', 'Ali Khan')
            ->set('accountNumber', '03001234567')
            ->set('amount', '1000')
            ->call('save');

        Livewire::test('wallet-loads.history')
            ->assertViewHas('providerTotals', function ($totals) {
                $row = $totals->firstWhere('provider', 'JazzCash');

                return $row && (float) $row->total === 1000.0;
            })
            ->assertSee('JazzCash');
    }

    public function test_a_historical_wallet_load_row_predating_this_change_still_displays_correctly(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        // Simulates a row created before this merge — its `provider` string
        // has no relationship to any current ShopAccount, exactly like real
        // historical data. It must still render everywhere unmodified.
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'SadaPay',
            'account_name' => 'Historical Recipient', 'account_number' => '03009999999',
            'amount' => 2500, 'total' => 2500,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertSee('SadaPay')
            ->assertSee('Historical Recipient')
            ->assertSee('Rs 2,500.00');

        $this->assertSame('SadaPay', $load->fresh()->provider);
    }

    public function test_the_provider_filter_on_history_is_sourced_from_actual_wallet_load_rows(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'NayaPay',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 100, 'total' => 100,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->set('provider', 'NayaPay')
            ->assertSee('X');
    }
}
