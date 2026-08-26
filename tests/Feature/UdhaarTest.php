<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UdhaarTest extends TestCase
{
    use RefreshDatabase;

    public function test_balance_accumulates_correctly_across_a_sequence_of_transactions(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        // Give 1000, give 500, repay 300 => balance should be 1200.
        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'given')->set('amount', '1000')->set('transaction_date', '2026-08-01')
            ->call('save')->assertHasNoErrors();

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'given')->set('amount', '500')->set('transaction_date', '2026-08-05')
            ->call('save')->assertHasNoErrors();

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'repayment')->set('amount', '300')->set('transaction_date', '2026-08-10')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(1200.0, $customer->fresh()->udhaarBalance());
        $this->assertSame('due', $customer->fresh()->udhaarStatus());
    }

    public function test_repaying_more_than_owed_results_in_an_advance_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'given')->set('amount', '500')->set('transaction_date', '2026-08-01')
            ->call('save');

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'repayment')->set('amount', '800')->set('transaction_date', '2026-08-05')
            ->call('save');

        $customer->refresh();
        $this->assertSame(-300.0, $customer->udhaarBalance());
        $this->assertSame('advance', $customer->udhaarStatus());
    }

    public function test_exactly_repaying_what_is_owed_results_in_settled_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'given')->set('amount', '500')->set('transaction_date', '2026-08-01')
            ->call('save');

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('type', 'repayment')->set('amount', '500')->set('transaction_date', '2026-08-05')
            ->call('save');

        $customer->refresh();
        $this->assertSame(0.0, $customer->udhaarBalance());
        $this->assertSame('settled', $customer->udhaarStatus());
    }

    public function test_reversing_a_given_transaction_correctly_removes_its_effect_on_balance(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $given = UdhaarTransaction::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'type' => 'given',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
        ]);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('reverse', $given->id)
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertSame(0.0, $customer->udhaarBalance());
        $this->assertSame(2, $customer->udhaarTransactions()->count());

        $reversal = UdhaarTransaction::where('reverses_transaction_id', $given->id)->firstOrFail();
        $this->assertSame('given_reversal', $reversal->type->value);
        $this->assertEquals(1000, $reversal->amount);

        // The original transaction itself is never mutated — it's still there, untouched.
        $this->assertEquals(1000, $given->fresh()->amount);
        $this->assertSame('given', $given->fresh()->type->value);
    }

    public function test_a_transaction_cannot_be_reversed_twice(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $given = UdhaarTransaction::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'type' => 'given',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
        ]);

        $this->actingAs($owner);

        $component = Livewire::test('udhaar.show', ['customer' => $customer]);
        $component->call('reverse', $given->id);
        $component->call('reverse', $given->id);

        // Only one reversal entry should exist, not two.
        $this->assertSame(1, UdhaarTransaction::where('reverses_transaction_id', $given->id)->count());
    }

    public function test_a_reversal_entry_cannot_itself_be_reversed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $given = UdhaarTransaction::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'user_id' => $owner->id,
            'type' => 'given',
            'amount' => 1000,
            'transaction_date' => '2026-08-01',
        ]);

        $this->actingAs($owner);

        $component = Livewire::test('udhaar.show', ['customer' => $customer]);
        $component->call('reverse', $given->id);

        $reversal = UdhaarTransaction::where('reverses_transaction_id', $given->id)->firstOrFail();
        $component->call('reverse', $reversal->id);

        // Reversing the reversal must not be allowed to happen.
        $this->assertSame(2, $customer->udhaarTransactions()->count());
    }

    public function test_transactions_are_correctly_isolated_between_customers_in_the_same_shop(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customerA = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $customerB = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal Ahmed']);

        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customerA->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-08-01']);
        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customerB->id, 'type' => 'given', 'amount' => 250, 'transaction_date' => '2026-08-01']);

        $this->assertSame(1000.0, $customerA->fresh()->udhaarBalance());
        $this->assertSame(250.0, $customerB->fresh()->udhaarBalance());

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customerA])
            ->assertSee('1,000.00')
            ->assertDontSee('250.00');
    }

    public function test_udhaar_data_is_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Ali Khan']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Bilal Ahmed']);

        UdhaarTransaction::create(['shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-08-01']);
        UdhaarTransaction::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'type' => 'given', 'amount' => 5000, 'transaction_date' => '2026-08-01']);

        $this->actingAs($ownerA);

        Livewire::test('udhaar.index')
            ->assertSee('Ali Khan')
            ->assertDontSee('Bilal Ahmed');

        // A tampered customer id from another shop must never be reachable.
        $this->get(route('udhaar.show', $customerB))->assertNotFound();
    }

    public function test_a_customer_with_udhaar_history_cannot_be_deleted(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        UdhaarTransaction::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-08-01']);

        $this->actingAs($owner);

        Livewire::test('customers.index')->call('delete', $customer->id);

        $this->assertNotNull($customer->fresh());
    }

    public function test_a_role_without_udhaar_access_cannot_reach_udhaar_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('udhaar.index'))->assertForbidden();
        $this->get(route('udhaar.show', $customer))->assertForbidden();
    }

    public function test_a_role_with_udhaar_access_can_reach_udhaar_screens(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['udhaar']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff);

        $this->get(route('udhaar.index'))->assertOk();
        $this->get(route('udhaar.show', $customer))->assertOk();
    }

    public function test_amount_and_date_are_required_and_amount_must_be_positive(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);

        $this->actingAs($owner);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->set('amount', '0')
            ->set('transaction_date', '')
            ->call('save')
            ->assertHasErrors(['amount', 'transaction_date']);
    }
}
