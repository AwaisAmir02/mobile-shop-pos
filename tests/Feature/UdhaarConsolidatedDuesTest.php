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

class UdhaarConsolidatedDuesTest extends TestCase
{
    use RefreshDatabase;

    protected function shopAndCustomer(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        return [$shop, $owner, $customer];
    }

    // ── Each source appears, individually labeled, never combined ───

    public function test_an_unpaid_sale_appears_in_the_consolidated_dues_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        $this->actingAs($owner);

        $sale = Sale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000,
        ]);
        $sale->payments()->create(['user_id' => $owner->id, 'amount' => 200, 'payment_date' => now()->toDateString()]);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertSee('Sale')
            ->assertSee($sale->invoiceNumber())
            ->assertSee('Rs 800.00');
    }

    public function test_an_owed_balance_load_appears_in_the_consolidated_dues_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertSee('Balance Load')
            ->assertSee('Rs 500.00');
    }

    public function test_a_fully_paid_item_does_not_appear_in_the_dues_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'paid', 'amount_paid' => 500,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertDontSee('Other Amounts Owed');
    }

    public function test_wallet_load_cash_out_is_excluded_from_the_dues_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        // Cash Out's "owed" amount means the shop owes the customer —
        // the opposite direction from every other source in this list —
        // so it must never appear here.
        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 700, 'total' => 700,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertDontSee('Other Amounts Owed')
            ->assertDontSee('Wallet Load');
    }

    public function test_wallet_load_cash_in_is_included_in_the_dues_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'direction' => 'cash_in', 'provider' => 'JazzCash', 'account_name' => 'Bilal', 'account_number' => '0300',
            'amount' => 700, 'total' => 700, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertSee('Wallet Load')
            ->assertSee('Rs 700.00');
    }

    public function test_all_six_sources_appear_simultaneously_each_separately_labeled(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $mainCategory = MainCategory::create(['shop_id' => $shop->id, 'name' => 'Accessory', 'slug' => 'accessory', 'is_builtin' => true]);
        $billCategory = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);

        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 200, 'total' => 200, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'bill_category_id' => $billCategory->id, 'consumer_number' => '1', 'consumer_name' => 'Bilal', 'amount' => 400, 'total' => 400, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'category' => $mainCategory->slug, 'description' => 'Screen replacement', 'amount' => 500, 'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'phone_number' => '0300', 'cnic_number' => '12345-1234567-1', 'amount' => 600, 'total' => 600, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 700, 'total' => 700, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertSee('Sale')
            ->assertSee('Wallet Load')
            ->assertSee('Balance Load')
            ->assertSee('Bill')
            ->assertSee('Repair')
            ->assertSee('NADRA Verification')
            ->assertSee('SIM Sale')
            ->assertSee('Rs 100.00')
            ->assertSee('Rs 200.00')
            ->assertSee('Rs 300.00')
            ->assertSee('Rs 400.00')
            ->assertSee('Rs 500.00')
            ->assertSee('Rs 600.00')
            ->assertSee('Rs 700.00');
    }

    // ── No combined figure is ever rendered ──────────────────────────

    public function test_no_combined_total_across_sources_is_rendered(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 300, 'total' => 300, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 200, 'total' => 200, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $this->actingAs($owner);

        // 300 + 200 = 500 — this combined figure must never appear anywhere.
        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertDontSee('Rs 500.00');
    }

    // ── Settling a due item updates the real underlying record ───────

    public function test_settling_a_due_balance_load_updates_the_real_record_and_it_disappears_from_the_list(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        $component = Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'balance_load', $load->id)
            ->set('dueSettleAmount', '500')
            ->call('submitDueSettlement')
            ->assertHasNoErrors();

        $load->refresh();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(500, $load->amount_paid);

        $component->assertDontSee('Other Amounts Owed');
    }

    public function test_settling_a_due_sale_creates_a_real_sale_payment_record(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'sale', $sale->id)
            ->set('dueSettleAmount', '1000')
            ->call('submitDueSettlement')
            ->assertHasNoErrors();

        $this->assertEquals(1000, $sale->payments()->sum('amount'));
        $this->assertSame(0.0, $sale->fresh()->withSum('payments', 'amount')->first()->amountDue());
    }

    public function test_the_settled_item_still_shows_in_full_on_its_own_history_screen(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'balance_load', $load->id)
            ->set('dueSettleAmount', '500')
            ->call('submitDueSettlement');

        // The audit trail lives on — it's just no longer "still owed".
        Livewire::test('balance-loads.history')->assertSee($load->receiptNumber());
    }

    public function test_a_settle_amount_greater_than_what_is_owed_is_rejected(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'balance_load', $load->id)
            ->set('dueSettleAmount', '99999')
            ->call('submitDueSettlement')
            ->assertHasErrors(['dueSettleAmount']);
    }

    public function test_a_tampered_source_id_belonging_to_another_customer_is_rejected(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();
        $otherCustomer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana Malik']);
        $otherLoad = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $otherCustomer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'balance_load', $otherLoad->id)
            ->set('dueSettleAmount', '500')
            ->call('submitDueSettlement');

        $otherLoad->refresh();
        $this->assertSame('unpaid', $otherLoad->payment_status->value);
    }

    // ── Coexistence with the Udhaar ledger itself ────────────────────

    public function test_the_udhaar_ledger_balance_is_unaffected_by_other_modules_dues(): void
    {
        [$shop, $owner, $customer] = $this->shopAndCustomer();

        $this->actingAs($owner);

        $customer->udhaarTransactions()->create([
            'user_id' => $owner->id, 'type' => 'given', 'amount' => 9000, 'transaction_date' => now()->toDateString(),
        ]);
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500,
            'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('balance', 9000.0)
            ->assertSee('Rs 9,000.00')
            ->assertSee('Other Amounts Owed');
    }
}
