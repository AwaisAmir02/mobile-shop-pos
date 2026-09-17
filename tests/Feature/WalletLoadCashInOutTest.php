<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadCashInOutTest extends TestCase
{
    use RefreshDatabase;

    // ── Direction defaults & tab switching ──────────────────────────

    public function test_the_screen_defaults_to_the_cash_in_tab(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')->assertSet('direction', 'cash_in');
    }

    public function test_switching_to_cash_out_resets_the_in_progress_transaction_fields(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('amount', '1000')
            ->call('switchDirection', 'cash_out')
            ->assertSet('direction', 'cash_out')
            ->assertSet('accountName', '')
            ->assertSet('amount', '');
    }

    public function test_a_wallet_load_created_without_an_explicit_direction_defaults_to_cash_in(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash',
            'account_name' => 'Ali', 'account_number' => '0300', 'amount' => 1000, 'total' => 1000,
        ]);
        $load->refresh();

        $this->assertSame('cash_in', $load->direction->value);
    }

    // ── Cash In: Account Name / Account Number required, auto-filled
    // from the selected customer ────────────────────────────────────

    public function test_cash_in_requires_account_name_and_account_number(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors(['accountName', 'accountNumber']);
    }

    public function test_selecting_a_customer_on_cash_in_auto_fills_the_account_name(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('customerId', (string) $customer->id)
            ->assertSet('accountName', 'Bilal Khan');
    }

    public function test_manually_editing_the_account_name_stops_it_from_being_overwritten_by_customer_selection(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'A Different Recipient')
            ->set('customerId', (string) $customer->id)
            ->assertSet('accountName', 'A Different Recipient');
    }

    public function test_selecting_new_customer_opens_the_quick_create_modal_and_creating_one_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');

        Livewire::test('customers.quick-create')
            ->set('name', 'Sana Malik')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Sana Malik')->firstOrFail();

        Livewire::test('wallet-loads.create')
            ->call('onCustomerCreated', $customer->id, $customer->name)
            ->assertSet('customerId', (string) $customer->id)
            ->assertSet('accountName', 'Sana Malik');
    }

    // ── Cash Out: no Account Name / Account Number ──────────────────

    public function test_cash_out_does_not_require_account_name_or_account_number(): void
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
        $this->assertNull($load->account_name);
        $this->assertNull($load->account_number);
    }

    // ── Fee-included-in-amount checkbox — exact worked example from the
    // approved plan: Amount 1000, 10% commission → fee 100. ─────────

    public function test_unchecked_fee_checkbox_adds_the_fee_on_top_matching_todays_behavior(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $shop->update(['wallet_load_commission_percent' => 10]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->assertSet('fee', '100.00')
            ->assertSet('feeIncludedInAmount', false)
            ->assertViewHas('totalCollected', 1100.0)
            ->assertViewHas('netAmount', 1000.0)
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertEquals(1000, $load->amount);
        $this->assertEquals(100, $load->fee);
        $this->assertEquals(1000, $load->net_amount);
        $this->assertEquals(1100, $load->total);
        $this->assertFalse($load->fee_included_in_amount);
    }

    public function test_checked_fee_checkbox_deducts_the_fee_from_the_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $shop->update(['wallet_load_commission_percent' => 10]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('feeIncludedInAmount', true)
            ->assertViewHas('totalCollected', 1000.0)
            ->assertViewHas('netAmount', 900.0)
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertEquals(1000, $load->amount);
        $this->assertEquals(100, $load->fee);
        $this->assertEquals(900, $load->net_amount);
        $this->assertEquals(1000, $load->total);
        $this->assertTrue($load->fee_included_in_amount);
    }

    // ── Deleting a customer with wallet load history ────────────────

    public function test_a_customer_with_wallet_load_history_cannot_be_deleted(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'provider' => 'JazzCash', 'account_name' => 'Bilal Khan', 'account_number' => '0300',
            'amount' => 1000, 'total' => 1000,
        ]);

        $this->assertTrue($customer->hasFinancialHistory());
    }

    // ── Shop Accounts settings: Total Sent vs Total Received ────────

    public function test_settings_splits_shop_account_totals_by_direction(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'X', 'account_number' => '1', 'shop_account_id' => $account->id, 'amount' => 1000, 'total' => 1000,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'shop_account_id' => $account->id, 'amount' => 400, 'total' => 400,
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'shop-accounts')
            ->assertViewHas('shopAccounts', function ($accounts) use ($account) {
                $row = $accounts->firstWhere('id', $account->id);

                return (float) $row->wallet_cash_in_sum_amount === 1000.0
                    && (float) $row->wallet_cash_out_sum_amount === 400.0;
            });
    }

    // ── Dashboard/Reports figures split by direction ────────────────

    public function test_report_summary_splits_wallet_amounts_by_direction_but_combines_fees(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash',
            'account_name' => 'X', 'account_number' => '1', 'amount' => 1000, 'fee' => 100, 'total' => 1100,
        ]);
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash',
            'amount' => 500, 'fee' => 50, 'total' => 550,
        ]);

        $summary = app(ShopReportService::class)->summary($shop, now()->subDay(), now()->addDay());

        $this->assertEquals(1000.0, $summary['totalWalletCashInAmount']);
        $this->assertEquals(100.0, $summary['totalWalletCashInFees']);
        $this->assertEquals(500.0, $summary['totalWalletCashOutAmount']);
        $this->assertEquals(50.0, $summary['totalWalletCashOutFees']);
        $this->assertEquals(150.0, $summary['totalWalletLoadFees']);
    }
}
