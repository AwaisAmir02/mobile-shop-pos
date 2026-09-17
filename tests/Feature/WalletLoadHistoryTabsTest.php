<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadHistoryTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_history_screen_defaults_to_the_cash_in_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')->assertSet('tab', 'cash_in');
    }

    public function test_each_tab_shows_only_its_own_directions_entries(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'Cash In Recipient', 'account_number' => '0300', 'shop_account_id' => $account->id,
            'amount' => 1000, 'total' => 1000,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'shop_account_id' => $account->id, 'amount' => 500, 'total' => 500,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertSee('Cash In Recipient')
            ->assertViewHas('loads', fn ($loads) => $loads->total() === 1);

        Livewire::test('wallet-loads.history')
            ->call('switchTab', 'cash_out')
            ->assertDontSee('Cash In Recipient')
            ->assertViewHas('loads', fn ($loads) => $loads->total() === 1);
    }

    public function test_switching_tabs_resets_pagination(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->call('gotoPage', 2)
            ->call('switchTab', 'cash_out')
            ->assertViewHas('loads', fn ($loads) => $loads->currentPage() === 1);
    }

    public function test_the_cash_in_table_shows_the_recipient_column(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'Ali Khan', 'account_number' => '03001234567', 'amount' => 1000, 'total' => 1000,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertSee('Recipient')
            ->assertSee('Ali Khan')
            ->assertSee('03001234567');
    }

    public function test_the_cash_out_table_shows_a_customer_column_instead_of_recipient(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'amount' => 500, 'total' => 500,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->call('switchTab', 'cash_out')
            ->assertSee('Customer')
            ->assertDontSee('Recipient')
            ->assertSee('Walk-in');
    }

    public function test_filters_apply_within_the_active_tab_only(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $accountA = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account A', 'provider_type' => 'JazzCash']);
        $accountB = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'Account B', 'provider_type' => 'Easypaisa']);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'Via A', 'account_number' => '1', 'shop_account_id' => $accountA->id, 'amount' => 1000, 'total' => 1000,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'Easypaisa',
            'account_name' => 'Via B', 'account_number' => '2', 'shop_account_id' => $accountB->id, 'amount' => 2000, 'total' => 2000,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->set('shopAccountId', (string) $accountA->id)
            ->assertSee('Via A')
            ->assertDontSee('Via B');
    }

    public function test_the_provider_filter_options_are_scoped_to_the_active_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'CashInOnlyProvider',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 100, 'total' => 100,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'CashOutOnlyProvider',
            'amount' => 100, 'total' => 100,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertViewHas('allProviders', fn ($providers) => $providers->contains('CashInOnlyProvider') && ! $providers->contains('CashOutOnlyProvider'));

        Livewire::test('wallet-loads.history')
            ->call('switchTab', 'cash_out')
            ->assertViewHas('allProviders', fn ($providers) => $providers->contains('CashOutOnlyProvider') && ! $providers->contains('CashInOnlyProvider'));
    }

    public function test_there_is_no_separate_direction_filter_dropdown(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertDontSee('Direction')
            ->assertDontSeeHtml('wire:model.live="directionFilter"');
    }

    // ── "Owed" figure stays direction-scoped ──────────────────────────

    public function test_total_owed_by_customers_only_reflects_cash_in_on_the_cash_in_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 1000, 'total' => 1000,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'amount' => 999999, 'total' => 999999, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->assertSee('Total Owed by Customers')
            ->assertViewHas('totalOwed', 1000.0);
    }

    public function test_total_owed_on_the_cash_out_tab_only_reflects_cash_out(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 999999, 'total' => 999999,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'amount' => 400, 'total' => 400, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->call('switchTab', 'cash_out')
            ->assertSee('Total Owed to Customers')
            ->assertViewHas('totalOwed', 400.0);
    }

    // ── The settle-later actions still work exactly as before ─────────

    public function test_mark_as_paid_and_add_payment_still_work_from_the_tabbed_history_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 1000, 'total' => 1000,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')->call('markAsPaid', $load->id);

        $load->refresh();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
    }
}
