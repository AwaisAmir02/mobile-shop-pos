<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\SimSale;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PartyLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_outstanding_and_udhaar_outstanding_are_never_combined_for_a_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $sale = Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);

        SalePayment::create([
            'shop_id' => $shop->id,
            'sale_id' => $sale->id,
            'user_id' => $owner->id,
            'amount' => 400,
            'payment_date' => now()->toDateString(),
        ]);

        UdhaarTransaction::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'type' => 'given',
            'amount' => 500,
            'transaction_date' => now()->toDateString(),
        ]);

        $this->actingAs($owner);

        Livewire::test('party-ledger.index')
            ->assertSee('Rs 600.00') // sales outstanding (1000 - 400)
            ->assertSee('Rs 500.00') // udhaar balance, kept separate
            ->assertDontSee('Rs 1,100.00'); // never combined into one figure
    }

    public function test_walk_in_sales_outstanding_aggregates_separately_from_named_customers(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Named Customer']);

        Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'customer_id' => null,
            'subtotal' => 300,
            'discount_amount' => 0,
            'total' => 300,
        ]);

        Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'customer_id' => null,
            'subtotal' => 200,
            'discount_amount' => 0,
            'total' => 200,
        ]);

        Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'customer_id' => $customer->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);

        $this->actingAs($owner);

        Livewire::test('party-ledger.index')
            ->assertSee('Walk-in')
            ->assertSee('Rs 500.00') // 300 + 200 walk-in sales, fully unpaid
            ->assertSee('Rs 1,000.00'); // named customer's own outstanding, untouched by walk-in
    }

    public function test_earnings_breakdown_splits_commission_from_sales_revenue_for_the_selected_period(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $today = now()->toDateString();

        Sale::create([
            'shop_id' => $shop->id,
            'user_id' => $owner->id,
            'subtotal' => 1000,
            'discount_amount' => 0,
            'total' => 1000,
        ]);

        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash',
            'account_name' => 'Ali', 'account_number' => '0300', 'shop_account_id' => $account->id,
            'amount' => 5000, 'fee' => 50, 'discount' => 0, 'total' => 5050,
        ]);

        BillPayment::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'consumer_number' => '123', 'consumer_name' => 'Test',
            'amount' => 2000, 'fee' => 30, 'discount' => 0, 'total' => 2030,
        ]);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz',
            'amount' => 500, 'fee' => 20, 'discount' => 0, 'total' => 520,
        ]);

        SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid',
            'sim_form' => 'physical', 'sim_number' => '0300', 'amount' => 150, 'fee' => 10, 'discount' => 0, 'total' => 160,
        ]);

        NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '0300', 'cnic_number' => '12345',
            'amount' => 100, 'fee' => 5, 'discount' => 0, 'total' => 105,
        ]);

        $this->actingAs($owner);

        Livewire::test('party-ledger.index')
            ->set('day', $today)
            ->assertSee('Rs 1,000.00') // sales revenue for the period
            ->assertSee('Rs 115.00'); // commission: 50 + 30 + 20 + 10 + 5
    }

    public function test_party_ledger_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);

        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Shop A Customer']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Shop B Customer']);

        UdhaarTransaction::create([
            'shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'type' => 'given',
            'amount' => 100, 'transaction_date' => now()->toDateString(),
        ]);

        UdhaarTransaction::create([
            'shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'type' => 'given',
            'amount' => 9999, 'transaction_date' => now()->toDateString(),
        ]);

        $this->actingAs($ownerA);

        Livewire::test('party-ledger.index')
            ->assertSee('Shop A Customer')
            ->assertDontSee('Shop B Customer')
            ->assertDontSee('9,999.00');
    }

    public function test_a_role_without_party_ledger_access_cannot_reach_the_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('party-ledger.index'))->assertForbidden();
    }

    public function test_a_role_with_party_ledger_access_can_reach_the_screen(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Accountant', 'permissions' => ['party-ledger']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('party-ledger.index'))->assertOk();
    }
}
