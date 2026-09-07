<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\SimSale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SimSalePaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sim_sale_defaults_to_paid_and_owes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('simNumber', '03001234567')
            ->set('amount', '500')
            ->call('save')
            ->assertHasNoErrors();

        $sale = SimSale::firstOrFail();
        $this->assertSame('paid', $sale->payment_status->value);
        $this->assertEquals(500, $sale->amount_paid);
        $this->assertSame(0.0, $sale->amountOwed());
    }

    public function test_a_partial_sim_sale_requires_and_stores_the_amount_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.create')
            ->set('simNumber', '03001234567')
            ->set('amount', '500')
            ->set('paymentStatus', 'partial')
            ->call('save')
            ->assertHasErrors(['amountPaid']);

        Livewire::test('sim-sales.create')
            ->set('simNumber', '03001234567')
            ->set('amount', '500')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '200')
            ->call('save')
            ->assertHasNoErrors();

        $sale = SimSale::firstOrFail();
        $this->assertSame('partial', $sale->payment_status->value);
        $this->assertEquals(200, $sale->amount_paid);
        $this->assertEquals(300, $sale->amountOwed());
    }

    public function test_marking_a_sim_sale_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $sale = SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid',
            'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 500, 'fee' => 0, 'discount' => 0,
            'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.history')->call('markAsPaid', $sale->id);

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status->value);
        $this->assertEquals(500, $sale->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $sale = SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid',
            'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 500, 'fee' => 0, 'discount' => 0,
            'total' => 500, 'payment_status' => 'partial', 'amount_paid' => 100,
        ]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.history')
            ->call('openAddPayment', $sale->id)
            ->set('paymentAmount', '400')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $sale->refresh();
        $this->assertSame('paid', $sale->payment_status->value);
        $this->assertEquals(500, $sale->amount_paid);
    }

    public function test_the_history_screen_can_filter_by_payment_status(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid',
            'sim_form' => 'physical', 'sim_number' => 'SETTLED-NUMBER', 'amount' => 500, 'fee' => 0, 'discount' => 0,
            'total' => 500, 'payment_status' => 'paid', 'amount_paid' => 500,
        ]);

        SimSale::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Zong', 'sim_type' => 'prepaid',
            'sim_form' => 'physical', 'sim_number' => 'OWING-NUMBER', 'amount' => 300, 'fee' => 0, 'discount' => 0,
            'total' => 300, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('sim-sales.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('OWING-NUMBER')
            ->assertDontSee('SETTLED-NUMBER');
    }
}
