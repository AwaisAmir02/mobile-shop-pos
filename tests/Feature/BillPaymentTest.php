<?php

namespace Tests\Feature;

use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BillPaymentTest extends TestCase
{
    use RefreshDatabase;

    // ── Category / provider CRUD & scoping ──────────────────────────

    public function test_default_bill_categories_and_providers_are_seeded_on_first_visit(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->assertSee('Electricity')
            ->assertSee('Gas')
            ->assertSee('Water')
            ->assertSee('Telephone');

        $this->assertSame(4, BillCategory::count());
        $this->assertTrue(BillProvider::where('name', 'LESCO')->exists());
        $this->assertTrue(BillProvider::where('name', 'K-Electric')->exists());
        $this->assertSame('Punjab', BillProvider::where('name', 'LESCO')->firstOrFail()->region);
    }

    public function test_owner_can_add_a_bill_category_and_provider(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('settings.index')
            ->set('billCategoryName', 'Internet')
            ->call('saveBillCategory')
            ->assertHasNoErrors();

        $category = BillCategory::where('name', 'Internet')->firstOrFail();

        Livewire::test('settings.index')
            ->set('billProviderCategoryId', (string) $category->id)
            ->set('billProviderName', 'StormFiber')
            ->set('billProviderRegion', 'Nationwide')
            ->call('saveBillProvider')
            ->assertHasNoErrors();

        $this->assertTrue(BillProvider::where('name', 'StormFiber')->where('bill_category_id', $category->id)->exists());
    }

    public function test_deleting_a_category_with_providers_under_it_is_blocked(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO', 'region' => 'Punjab']);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteBillCategory', $category->id);

        $this->assertNotNull($category->fresh());
    }

    public function test_deleting_a_provider_in_use_is_blocked_and_history_stays_intact(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO', 'region' => 'Punjab']);
        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id, 'consumer_number' => '123', 'consumer_name' => 'Test', 'amount' => 1000, 'fee' => 0, 'discount' => 0, 'total' => 1000]);

        $this->actingAs($owner);

        Livewire::test('settings.index')->call('deleteBillProvider', $provider->id);

        $this->assertNotNull($provider->fresh());
    }

    public function test_bill_categories_and_providers_are_scoped_per_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $categoryA = BillCategory::create(['shop_id' => $shopA->id, 'name' => 'Electricity A']);
        BillProvider::create(['shop_id' => $shopA->id, 'bill_category_id' => $categoryA->id, 'name' => 'Provider A']);
        $categoryB = BillCategory::create(['shop_id' => $shopB->id, 'name' => 'Electricity B']);
        BillProvider::create(['shop_id' => $shopB->id, 'bill_category_id' => $categoryB->id, 'name' => 'Provider B']);

        $this->actingAs($ownerA);

        Livewire::test('settings.index')
            ->set('tab', 'bills')
            ->assertSee('Electricity A')
            ->assertDontSee('Electricity B')
            ->assertSee('Provider A')
            ->assertDontSee('Provider B');
    }

    // ── Fee / discount / total math ──────────────────────────────────

    public function test_saving_a_bill_payment_computes_the_total_as_amount_plus_fee_minus_discount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO', 'region' => 'Punjab']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('shopAccountId', (string) $account->id)
            ->set('consumerNumber', 'C-123')
            ->set('consumerName', 'Ali Khan')
            ->set('amount', '5000')
            ->set('fee', '100')
            ->set('discount', '20')
            ->assertViewHas('totalCollected', 5080.0)
            ->call('save')
            ->assertHasNoErrors();

        $payment = BillPayment::firstOrFail();
        $this->assertEquals(5000, $payment->amount);
        $this->assertEquals(100, $payment->fee);
        $this->assertEquals(20, $payment->discount);
        $this->assertEquals(5080, $payment->total);
        $this->assertSame($provider->id, $payment->bill_provider_id);
    }

    public function test_a_provider_belonging_to_a_different_category_than_selected_is_rejected(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $electricity = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $gas = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Gas']);
        $gasProvider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $gas->id, 'name' => 'SSGC']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $electricity->id)
            ->set('billProviderId', (string) $gasProvider->id)
            ->set('consumerNumber', 'C-123')
            ->set('consumerName', 'Ali Khan')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors(['billProviderId']);

        $this->assertSame(0, BillPayment::count());
    }

    public function test_a_bill_payment_can_be_linked_to_a_customer_or_left_as_walk_in(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('billCategoryId', (string) $category->id)
            ->set('billProviderId', (string) $provider->id)
            ->set('shopAccountId', (string) $account->id)
            ->set('customerId', (string) $customer->id)
            ->set('consumerNumber', 'C-123')
            ->set('consumerName', 'Ali Khan')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, BillPayment::firstOrFail()->customer_id);
    }

    public function test_selecting_new_customer_from_the_bills_screen_opens_the_shared_quick_create_component(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('bills.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');
    }

    // ── Tenant isolation ──────────────────────────────────────────────

    public function test_bill_payments_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);
        $categoryA = BillCategory::create(['shop_id' => $shopA->id, 'name' => 'Electricity']);
        $providerA = BillProvider::create(['shop_id' => $shopA->id, 'bill_category_id' => $categoryA->id, 'name' => 'LESCO']);
        $categoryB = BillCategory::create(['shop_id' => $shopB->id, 'name' => 'Electricity']);
        $providerB = BillProvider::create(['shop_id' => $shopB->id, 'bill_category_id' => $categoryB->id, 'name' => 'K-Electric']);

        BillPayment::create(['shop_id' => $shopA->id, 'user_id' => $ownerA->id, 'bill_category_id' => $categoryA->id, 'bill_provider_id' => $providerA->id, 'consumer_number' => 'A-1', 'consumer_name' => 'A', 'amount' => 100, 'fee' => 0, 'discount' => 0, 'total' => 100]);
        BillPayment::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'bill_category_id' => $categoryB->id, 'bill_provider_id' => $providerB->id, 'consumer_number' => 'B-1', 'consumer_name' => 'B', 'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200]);

        $this->actingAs($ownerA);

        Livewire::test('bills.history')
            ->assertSee('A-1')
            ->assertDontSee('B-1');

        $this->assertSame(1, BillPayment::count());
    }

    // ── Permission gating ─────────────────────────────────────────────

    public function test_a_role_without_bills_access_cannot_reach_bill_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('bills.index'))->assertForbidden();
        $this->get(route('bills.history'))->assertForbidden();
    }

    public function test_a_role_with_bills_access_can_reach_bill_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Bills Agent', 'permissions' => ['bills']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('bills.index'))->assertOk();
        $this->get(route('bills.history'))->assertOk();
    }

    // ── Super Admin module toggle ─────────────────────────────────────

    public function test_super_admin_can_disable_the_bills_module_for_a_shop(): void
    {
        $admin = User::factory()->create(['shop_id' => null, 'is_super_admin' => true]);
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);
        $this->get(route('bills.index'))->assertOk();

        $this->actingAs($admin);
        Livewire::test('admin.shops.show', ['shop' => $shop])->call('toggleScreen', 'bills');

        $this->assertTrue($shop->fresh()->isScreenDisabled('bills'));

        $this->actingAs($owner->fresh());
        $this->get(route('bills.index'))->assertForbidden();
    }

    public function test_bills_revenue_appears_as_its_own_line_in_reports(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();
        $category = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $provider = BillProvider::create(['shop_id' => $shop->id, 'bill_category_id' => $category->id, 'name' => 'LESCO']);

        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $category->id, 'bill_provider_id' => $provider->id, 'consumer_number' => 'X', 'consumer_name' => 'X', 'amount' => 5000, 'fee' => 100, 'discount' => 0, 'total' => 5100]);

        $this->actingAs($owner);

        Livewire::test('reports.index')
            ->set('day', $today)
            ->assertViewHas('totalBillsCollected', 5000.0)
            ->assertViewHas('totalBillsFeeRevenue', 100.0)
            ->assertViewHas('totalRevenue', 0.0);
    }
}
