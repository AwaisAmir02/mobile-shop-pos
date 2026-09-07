<?php

namespace Tests\Feature;

use App\Models\NadraVerification;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class NadraVerificationPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_verification_defaults_to_paid_and_owes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->call('save')
            ->assertHasNoErrors();

        $verification = NadraVerification::firstOrFail();
        $this->assertSame('paid', $verification->payment_status->value);
        $this->assertEquals(200, $verification->amount_paid);
        $this->assertSame(0.0, $verification->amountOwed());
    }

    public function test_a_partial_verification_requires_and_stores_the_amount_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->set('paymentStatus', 'partial')
            ->call('save')
            ->assertHasErrors(['amountPaid']);

        Livewire::test('nadra-verifications.create')
            ->set('phoneNumber', '03001234567')
            ->set('cnicNumber', '12345-1234567-1')
            ->set('amount', '200')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '80')
            ->call('save')
            ->assertHasNoErrors();

        $verification = NadraVerification::firstOrFail();
        $this->assertSame('partial', $verification->payment_status->value);
        $this->assertEquals(80, $verification->amount_paid);
        $this->assertEquals(120, $verification->amountOwed());
    }

    public function test_marking_a_verification_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $verification = NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03001234567', 'cnic_number' => '12345-1234567-1',
            'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.history')->call('markAsPaid', $verification->id);

        $verification->refresh();
        $this->assertSame('paid', $verification->payment_status->value);
        $this->assertEquals(200, $verification->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $verification = NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03001234567', 'cnic_number' => '12345-1234567-1',
            'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200, 'payment_status' => 'partial', 'amount_paid' => 50,
        ]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.history')
            ->call('openAddPayment', $verification->id)
            ->set('paymentAmount', '150')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $verification->refresh();
        $this->assertSame('paid', $verification->payment_status->value);
        $this->assertEquals(200, $verification->amount_paid);
    }

    public function test_the_history_screen_can_filter_by_payment_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03000000000', 'cnic_number' => '11111-1111111-1',
            'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200, 'payment_status' => 'paid', 'amount_paid' => 200,
        ]);

        NadraVerification::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '03111111111', 'cnic_number' => '22222-2222222-2',
            'amount' => 300, 'fee' => 0, 'discount' => 0, 'total' => 300, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('nadra-verifications.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('22222-2222222-2')
            ->assertDontSee('11111-1111111-1');
    }
}
