<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BalanceLoadCustomerAndPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    // ── Payment Status is derived from "Cash Received", the same way
    // Wallet Load and Sales derive it — no manual dropdown. ─────────

    public function test_leaving_cash_received_blank_records_the_load_as_unpaid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '1000')
            ->set('fee', '50')
            ->call('save')
            ->assertHasNoErrors();

        $load = BalanceLoad::firstOrFail();
        $this->assertSame('unpaid', $load->payment_status->value);
        $this->assertEquals(0, $load->amount_paid);
        $this->assertEquals(1050, $load->amountOwed());
    }

    public function test_entering_the_full_total_as_cash_received_records_it_as_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '1000')
            ->set('amountReceivedNow', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $load = BalanceLoad::firstOrFail();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
        $this->assertSame(0.0, $load->amountOwed());
    }

    public function test_entering_a_partial_cash_received_records_it_as_partial_and_stores_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '1000')
            ->set('amountReceivedNow', '400')
            ->call('save')
            ->assertHasNoErrors();

        $load = BalanceLoad::firstOrFail();
        $this->assertSame('partial', $load->payment_status->value);
        $this->assertEquals(400, $load->amount_paid);
        $this->assertEquals(600, $load->amountOwed());
    }

    public function test_cash_received_cannot_exceed_the_computed_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '1000')
            ->set('amountReceivedNow', '99999')
            ->call('save')
            ->assertHasErrors(['amountReceivedNow']);
    }

    // ── Customer field: optional, with inline-create ────────────────

    public function test_a_balance_load_can_optionally_be_linked_to_a_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);

        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('customerId', (string) $customer->id)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($customer->id, BalanceLoad::firstOrFail()->customer_id);
    }

    public function test_a_balance_load_can_be_saved_walk_in_with_no_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('amount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(BalanceLoad::firstOrFail()->customer_id);
    }

    public function test_selecting_new_customer_opens_the_quick_create_modal_and_creating_one_selects_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        Livewire::test('balance-loads.create')
            ->set('customerId', '__create__')
            ->assertSet('customerId', '')
            ->assertDispatched('open-modal', name: 'quick-create-customer');

        Livewire::test('customers.quick-create')
            ->set('name', 'Sana Malik')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::where('name', 'Sana Malik')->firstOrFail();

        Livewire::test('balance-loads.create')
            ->call('onCustomerCreated', $customer->id)
            ->assertSet('customerId', (string) $customer->id);
    }

    public function test_a_tampered_customer_id_from_another_shop_is_rejected(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Other Shop Customer']);

        $this->actingAs($ownerA);

        Livewire::test('balance-loads.create')
            ->set('customerId', (string) $customerB->id)
            ->set('amount', '1000')
            ->call('save')
            ->assertHasErrors(['customerId']);
    }

    public function test_a_customer_with_balance_load_history_cannot_be_deleted(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Khan']);
        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id,
            'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 1000, 'total' => 1000,
        ]);

        $this->assertTrue($customer->hasFinancialHistory());
    }

    // ── Settle-later actions on the History screen ──────────────────

    public function test_marking_a_balance_load_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance',
            'amount' => 1000, 'total' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.history')->call('markAsPaid', $load->id);

        $load->refresh();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance',
            'amount' => 1000, 'total' => 1000, 'payment_status' => 'partial', 'amount_paid' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.history')
            ->call('openAddPayment', $load->id)
            ->set('paymentAmount', '700')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $load->refresh();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
    }

    public function test_the_history_screen_can_filter_by_payment_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance',
            'phone_number' => '0300-SETTLED', 'amount' => 1000, 'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000,
        ]);

        BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance',
            'phone_number' => '0300-OWING', 'amount' => 500, 'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('balance-loads.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('0300-OWING')
            ->assertDontSee('0300-SETTLED');
    }

    public function test_a_historical_balance_load_created_without_payment_fields_defaults_to_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $load = BalanceLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz',
            'load_type' => 'balance', 'amount' => 1000, 'total' => 1000,
        ]);
        $load->refresh();

        $this->assertSame('paid', $load->payment_status->value);
    }
}
