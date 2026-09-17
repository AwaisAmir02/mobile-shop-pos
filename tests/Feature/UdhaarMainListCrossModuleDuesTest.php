<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The main Udhaar list previously only surfaced customers with an actual
 * udhaar_transactions row, so a customer who never took a real loan but
 * has, say, a partial Sale was unreachable from this screen entirely —
 * even though their per-customer page's "Other Amounts Owed" section
 * already knew how to show and settle it. These tests confirm every
 * source now makes a customer reachable here, while the "Total
 * Outstanding" figure stays scoped to real Udhaar balances only.
 */
class UdhaarMainListCrossModuleDuesTest extends TestCase
{
    use RefreshDatabase;

    protected function shopAndCustomer(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        return [$shop, $owner, $customer];
    }

    public function test_a_customer_with_no_udhaar_activity_but_a_partial_sale_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 200, 'payment_date' => now()->toDateString()]);

        Livewire::test('udhaar.index')
            ->assertSee('Bilal Khan')
            ->assertSee('Other Dues')
            ->assertSee('Settled');
    }

    public function test_a_customer_with_no_udhaar_activity_but_a_partial_sim_sale_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234567',
            'amount' => 500, 'total' => 500, 'payment_status' => 'partial', 'amount_paid' => 100,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_with_no_udhaar_activity_but_an_owed_wallet_load_cash_in_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 700, 'total' => 700,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_with_no_udhaar_activity_but_an_owed_balance_load_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_with_no_udhaar_activity_but_an_owed_bill_payment_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);
        $billCategory = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);

        BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'bill_category_id' => $billCategory->id, 'consumer_number' => '1', 'consumer_name' => 'Bilal Khan',
            'amount' => 400, 'total' => 400, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_with_no_udhaar_activity_but_an_owed_repair_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);
        $mainCategory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);

        Repair::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'category' => $mainCategory->slug, 'description' => 'Screen replacement',
            'amount' => 500, 'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_with_no_udhaar_activity_but_an_owed_nadra_verification_appears_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'phone_number' => '0300', 'cnic_number' => '12345-1234567-1',
            'amount' => 600, 'total' => 600, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')->assertSee('Bilal Khan')->assertSee('Other Dues');
    }

    public function test_a_customer_fully_settled_everywhere_does_not_appear_on_the_main_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300,
            'payment_status' => 'paid', 'amount_paid' => 300,
        ]);

        // Note: the "+ New Customer" transaction-picker dropdown lists every
        // customer in the shop regardless of due status, so assertDontSee()
        // on the page as a whole would collide with it — the rows data
        // itself is the precise thing to check.
        Livewire::test('udhaar.index')
            ->assertViewHas('rows', fn ($rows) => ! $rows->pluck('customer.id')->contains($customer->id));
    }

    public function test_a_customer_whose_only_outstanding_item_is_a_wallet_load_cash_out_shortfall_does_not_appear(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 700, 'total' => 700,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')
            ->assertViewHas('rows', fn ($rows) => ! $rows->pluck('customer.id')->contains($customer->id));
    }

    public function test_total_outstanding_stays_scoped_to_actual_udhaar_loans_and_is_unaffected_by_cross_module_dues(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $otherCustomer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana Malik']);
        $this->actingAs($owner);

        // A real Udhaar loan for one customer...
        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 5000, 'transaction_date' => now()->toDateString(),
        ]);

        // ...and a large cross-module due for a different customer, which
        // must never leak into the Udhaar-only total.
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $otherCustomer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 999999, 'total' => 999999,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')
            ->assertSee('Bilal Khan')
            ->assertSee('Sana Malik')
            ->assertViewHas('totalDue', 5000.0)
            ->assertDontSee('Rs 1,004,999.00');
    }

    public function test_a_customer_with_other_dues_still_links_to_their_full_per_customer_page(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.index')
            ->assertSee(route('udhaar.show', $customer), false);
    }

    public function test_cross_module_dues_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        BalanceLoad::create([
            'shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'customer_id' => $customerB->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($ownerA);

        Livewire::test('udhaar.index')->assertDontSee('Shop B Customer');
    }
}
