<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Udhaar screens' headline balance/status/Total Outstanding now combine
 * the real Udhaar loan ledger with every other-source due already itemized
 * in "Other Amounts Owed" — a deliberate, explicit change from the earlier
 * "never combine" rule (see the superseded assertions this replaced in
 * UdhaarConsolidatedDuesTest and UdhaarMainListCrossModuleDuesTest). This
 * is scoped to Udhaar only: Party Ledger's own separate Sales/Udhaar
 * Outstanding cards must stay exactly as they were.
 */
class UdhaarCombinedBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function shopAndCustomer(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        return [$shop, $owner, $customer];
    }

    public function test_a_real_loan_plus_an_other_source_due_produces_the_combined_headline_balance(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('balance', 1500.0)
            ->assertViewHas('status', 'due');

        Livewire::test('udhaar.index')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('customer.id', $customer->id)['balance'] === 1500.0);
    }

    public function test_the_itemized_other_amounts_owed_list_still_shows_each_item_separately(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('dues', fn ($dues) => $dues->count() === 1
                && $dues->first()['sourceLabel'] === 'Balance Load'
                && $dues->first()['amountDue'] === 500.0);
    }

    public function test_settling_an_other_source_item_reduces_the_combined_headline_balance_by_exactly_that_amount(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])->assertViewHas('balance', 1500.0);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'balance_load', $load->id)
            ->set('dueSettleAmount', '200')
            ->call('submitDueSettlement')
            ->assertHasNoErrors();

        // 1500 - 200 partial settlement = 1300, exactly the amount settled.
        Livewire::test('udhaar.show', ['customer' => $customer])->assertViewHas('balance', 1300.0);
    }

    public function test_a_customer_with_zero_real_loan_activity_shows_the_other_sources_total_as_the_headline(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 750, 'total' => 750,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('balance', 750.0)
            ->assertViewHas('status', 'due');

        Livewire::test('udhaar.index')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('customer.id', $customer->id)['balance'] === 750.0);
    }

    public function test_wallet_load_cash_out_never_counts_toward_the_combined_headline_balance(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);

        // The shop owes the customer here, not the other way around — must
        // never be added to what the customer owes.
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 5000, 'total' => 5000,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])->assertViewHas('balance', 1000.0);
        Livewire::test('udhaar.index')
            ->assertViewHas('rows', fn ($rows) => $rows->firstWhere('customer.id', $customer->id)['balance'] === 1000.0);
    }

    public function test_the_combined_balance_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Customer A']);
        $customerBInShopB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B']);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        $this->actingAs($ownerA);
        $customerA->udhaarTransactions()->create([
            'user_id' => $ownerA->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);

        BalanceLoad::create([
            'shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'customer_id' => $customerBInShopB->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 999999, 'total' => 999999,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customerA])->assertViewHas('balance', 1000.0);
        Livewire::test('udhaar.index')->assertViewHas('totalDue', 1000.0);
    }

    // ── Party Ledger stays completely unaffected by this change ──────

    public function test_party_ledgers_sales_and_udhaar_outstanding_cards_stay_separate_and_unaffected(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString(),
        ]);
        Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 300, 'discount_amount' => 0, 'total' => 300]);
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        // Udhaar's own headline now combines everything (1000 + 300 + 500 = 1800)...
        Livewire::test('udhaar.show', ['customer' => $customer])->assertViewHas('balance', 1800.0);

        // ...but Party Ledger keeps Sales Outstanding (300) and Udhaar
        // Outstanding (1000) as their own separate, un-combined figures —
        // it never even sees the Balance Load due.
        Livewire::test('party-ledger.index')
            ->assertViewHas('rows', function ($rows) use ($customer) {
                $row = collect($rows)->firstWhere('customer.id', $customer->id);

                return $row['salesOutstanding'] === 300.0 && $row['udhaarBalance'] === 1000.0;
            });
    }
}
