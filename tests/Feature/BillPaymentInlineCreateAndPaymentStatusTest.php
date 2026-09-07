<?php

namespace Tests\Feature;

use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillPaymentInlineCreateAndPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    // ── Inline-create: Category ─────────────────────────────────────

    public function test_selecting_new_category_opens_the_quick_create_modal_and_reverts_the_select(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->assertSet('billCategoryId', (string) $category->id)
            ->set('billCategoryId', '__create__')
            ->assertSet('billCategoryId', (string) $category->id)
            ->assertDispatched('open-modal', name: 'quick-create-bill-category');
    }

    public function test_creating_a_bill_category_inline_creates_it_and_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        $bills = Livewire::test('bills.create');
        $quickCreate = Livewire::test('bill-categories.quick-create');

        $quickCreate
            ->set('name', 'Internet')
            ->call('save')
            ->assertHasNoErrors();

        $category = BillCategory::where('name', 'Internet')->firstOrFail();
        $this->assertSame($shop->id, $category->shop_id);

        $bills->call('onBillCategoryCreated', $category->id);
        $bills->assertSet('billCategoryId', (string) $category->id);
    }

    // ── Inline-create: Provider (and Region routes through it) ───────

    public function test_selecting_new_provider_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billProviderId', '__create__')
            ->assertSet('billProviderId', '')
            ->assertDispatched('open-modal', name: 'quick-create-bill-provider');
    }

    public function test_selecting_new_region_also_opens_the_provider_quick_create_modal(): void
    {
        // Region is a plain attribute on a Provider record, not its own
        // manageable list, so "+ New" under Region routes through the same
        // Provider creation modal rather than a nonexistent "Region" entity.
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('region', '__create__')
            ->assertSet('region', '')
            ->assertDispatched('open-modal', name: 'quick-create-bill-provider');
    }

    public function test_creating_a_bill_provider_inline_creates_it_and_selects_it_and_its_category(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $electricity = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $gas = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Gas']);

        $this->actingAs($owner);

        $bills = Livewire::test('bills.create')->set('billCategoryId', (string) $electricity->id);

        // The quick-create defaults to whatever category is currently
        // selected on the Bills screen, but the user can still change it
        // inside the modal — here they pick Gas instead.
        $quickCreate = Livewire::test('bill-providers.quick-create', ['defaultBillCategoryId' => (string) $electricity->id])
            ->assertSet('billCategoryId', (string) $electricity->id)
            ->set('billCategoryId', (string) $gas->id)
            ->set('name', 'SNGPL')
            ->set('region', 'Punjab')
            ->call('save')
            ->assertHasNoErrors();

        $provider = BillProvider::where('name', 'SNGPL')->firstOrFail();
        $this->assertSame($gas->id, $provider->bill_category_id);
        $this->assertSame('Punjab', $provider->region);

        $bills->call('onBillProviderCreated', $provider->id, $provider->bill_category_id);
        $bills->assertSet('billCategoryId', (string) $gas->id);
        $bills->assertSet('billProviderId', (string) $provider->id);
    }

    // ── Send From (shop account) ──────────────────────────────────────

    public function test_selecting_new_shop_account_from_bills_opens_the_quick_create_modal(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('shopAccountId', '__create__')
            ->assertSet('shopAccountId', '')
            ->assertDispatched('open-modal', name: 'quick-create-shop-account');
    }

    public function test_saving_a_bill_payment_stores_the_shop_account_it_was_sent_from(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('shopAccountId', (string) $account->id)
            ->set('consumerNumber', 'C-1')
            ->set('consumerName', 'Ali')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($account->id, BillPayment::firstOrFail()->shop_account_id);
    }

    public function test_shop_account_totals_include_bill_payments_alongside_wallet_loads(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);

        BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id,
            'bill_provider_id' => $provider->id, 'shop_account_id' => $account->id,
            'consumer_number' => 'C-1', 'consumer_name' => 'Ali', 'amount' => 500, 'fee' => 0, 'discount' => 0, 'total' => 500,
        ]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Bilal',
            'account_number' => '0300', 'shop_account_id' => $account->id, 'amount' => 300, 'fee' => 0, 'discount' => 0, 'total' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('tab', 'shop-accounts')
            ->assertSee('Rs 800.00');
    }

    // ── Payment Status ──────────────────────────────────────────────

    public function test_a_bill_payment_defaults_to_paid_and_owes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('shopAccountId', (string) $account->id)
            ->set('consumerNumber', 'C-1')
            ->set('consumerName', 'Ali')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $payment = BillPayment::firstOrFail();
        $this->assertSame('paid', $payment->payment_status->value);
        $this->assertEquals(1000, $payment->amount_paid);
        $this->assertSame(0.0, $payment->amountOwed());
    }

    public function test_a_partial_bill_payment_requires_and_stores_the_amount_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('shopAccountId', (string) $account->id)
            ->set('consumerNumber', 'C-1')
            ->set('consumerName', 'Ali')
            ->set('amount', '1000')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '400')
            ->call('save')
            ->assertHasNoErrors();

        $payment = BillPayment::firstOrFail();
        $this->assertSame('partial', $payment->payment_status->value);
        $this->assertEquals(400, $payment->amount_paid);
        $this->assertEquals(600, $payment->amountOwed());
    }

    public function test_marking_a_bill_payment_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $payment = BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id,
            'consumer_number' => 'C-1', 'consumer_name' => 'Ali', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('bills.history')->call('markAsPaid', $payment->id);

        $payment->refresh();
        $this->assertSame('paid', $payment->payment_status->value);
        $this->assertEquals(1000, $payment->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $payment = BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id,
            'consumer_number' => 'C-1', 'consumer_name' => 'Ali', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000,
            'payment_status' => 'partial', 'amount_paid' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('bills.history')
            ->call('openAddPayment', $payment->id)
            ->set('paymentAmount', '700')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $payment->refresh();
        $this->assertSame('paid', $payment->payment_status->value);
        $this->assertEquals(1000, $payment->amount_paid);
    }

    public function test_the_history_screen_can_filter_by_payment_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);

        BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id,
            'consumer_number' => 'CONSUMER-FULLY-SETTLED', 'consumer_name' => 'Ali', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000,
            'payment_status' => 'paid', 'amount_paid' => 1000,
        ]);

        BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id,
            'consumer_number' => 'CONSUMER-STILL-OWING', 'consumer_name' => 'Bilal', 'amount' => 500, 'fee' => 0, 'discount' => 0, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('bills.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('CONSUMER-STILL-OWING')
            ->assertDontSee('CONSUMER-FULLY-SETTLED');
    }

    public function test_total_owed_by_customers_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $categoryA = BillCategory::create(['shop_id' => $shopA->id, 'name' => 'Electricity']);
        $providerA = BillProvider::create(['shop_id' => $shopA->id, 'bill_category_id' => $categoryA->id, 'name' => 'LESCO']);
        $categoryB = BillCategory::create(['shop_id' => $shopB->id, 'name' => 'Electricity']);
        $providerB = BillProvider::create(['shop_id' => $shopB->id, 'bill_category_id' => $categoryB->id, 'name' => 'K-Electric']);

        BillPayment::create([
            'shop_id' => $shopA->id, 'bill_category_id' => $categoryA->id, 'bill_provider_id' => $providerA->id,
            'consumer_number' => 'A-1', 'consumer_name' => 'A', 'amount' => 100, 'fee' => 0, 'discount' => 0, 'total' => 100,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        BillPayment::create([
            'shop_id' => $shopB->id, 'bill_category_id' => $categoryB->id, 'bill_provider_id' => $providerB->id,
            'consumer_number' => 'B-1', 'consumer_name' => 'B', 'amount' => 9999, 'fee' => 0, 'discount' => 0, 'total' => 9999,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($ownerA);

        Livewire::test('bills.history')
            ->assertSee('Rs 100.00');
    }
}
