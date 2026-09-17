<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\WalletLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WalletLoadPaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    // ── Payment Status is derived from "Cash Received" / "Amount Received
    // in Account", the same way Sales derives it from "Amount Paid Now" —
    // there is no manual dropdown on this screen. ──────────────────────

    public function test_leaving_amount_received_blank_records_the_load_as_unpaid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('fee', '50')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertSame('unpaid', $load->payment_status->value);
        $this->assertEquals(0, $load->amount_paid);
        $this->assertEquals(1050, $load->amountOwed());
    }

    public function test_entering_zero_as_amount_received_also_records_it_as_unpaid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('amountReceivedNow', '0')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('unpaid', WalletLoad::firstOrFail()->payment_status->value);
    }

    public function test_entering_the_full_total_as_amount_received_records_it_as_paid(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('amountReceivedNow', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
        $this->assertSame(0.0, $load->amountOwed());
    }

    public function test_entering_a_partial_amount_received_records_it_as_partial_and_stores_it(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('amountReceivedNow', '400')
            ->call('save')
            ->assertHasNoErrors();

        $load = WalletLoad::firstOrFail();
        $this->assertSame('partial', $load->payment_status->value);
        $this->assertEquals(400, $load->amount_paid);
        $this->assertEquals(600, $load->amountOwed());
    }

    public function test_amount_received_cannot_exceed_the_computed_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.create')
            ->set('accountName', 'Ali')
            ->set('accountNumber', '0300')
            ->set('shopAccountId', (string) $account->id)
            ->set('amount', '1000')
            ->set('amountReceivedNow', '99999')
            ->call('save')
            ->assertHasErrors(['amountReceivedNow']);
    }

    // ── Settle-later actions on the History screen (unaffected by the
    // create-time derivation change) ────────────────────────────────

    public function test_marking_a_wallet_load_as_paid_zeroes_out_what_is_owed(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Ali',
            'account_number' => '0300', 'shop_account_id' => $account->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0,
            'total' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')->call('markAsPaid', $load->id);

        $load->refresh();
        $this->assertSame('paid', $load->payment_status->value);
        $this->assertEquals(1000, $load->amount_paid);
    }

    public function test_adding_a_partial_payment_reduces_what_is_owed_without_overwriting_history(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);
        $load = WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Ali',
            'account_number' => '0300', 'shop_account_id' => $account->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0,
            'total' => 1000, 'payment_status' => 'partial', 'amount_paid' => 300,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
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
        $account = ShopAccount::create(['shop_id' => $shop->id, 'name' => 'My JazzCash', 'provider_type' => 'JazzCash']);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Settled Recipient',
            'account_number' => '0300', 'shop_account_id' => $account->id, 'amount' => 1000, 'fee' => 0, 'discount' => 0,
            'total' => 1000, 'payment_status' => 'paid', 'amount_paid' => 1000,
        ]);

        WalletLoad::create([
            'shop_id' => $shop->id, 'user_id' => $owner->id, 'provider' => 'JazzCash', 'account_name' => 'Owing Recipient',
            'account_number' => '0301', 'shop_account_id' => $account->id, 'amount' => 500, 'fee' => 0, 'discount' => 0,
            'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0,
        ]);

        $this->actingAs($owner);

        Livewire::test('wallet-loads.history')
            ->set('paymentStatusFilter', 'unpaid')
            ->assertSee('Owing Recipient')
            ->assertDontSee('Settled Recipient');
    }
}
