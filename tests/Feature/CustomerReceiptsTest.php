<?php

namespace Tests\Feature;

use App\Models\CustomerReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Receive Payment (W1): one lump sum pays udhaar, then the oldest bills, and
 * keeps the rest as an advance, with a numbered receipt.
 */
class CustomerReceiptsTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_a_lump_sum_pays_udhaar_then_the_oldest_bills_and_keeps_the_rest_as_advance(): void
    {
        $product = $this->product(['wholesale_price' => null]);
        $customer = $this->customer();
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 500, 'transaction_date' => '2026-10-01']);
        $older = $this->sell($customer, $product, 10, 0, '2026-10-05');
        $newer = $this->sell($customer, $product, 20, 500, '2026-11-02');

        Livewire::test('customers.show', ['customer' => $customer])
            ->call('openReceive')
            ->set('receiptAmount', '3500')
            ->assertSee('Kept as udhaar advance')
            ->call('receive')
            ->assertHasNoErrors();

        $receipt = CustomerReceipt::sole();
        $this->assertSame('3500.00', $receipt->amount);
        $this->assertSame([['udhaar', 500.0], ['sale', 1000.0], ['sale', 1500.0], ['advance', 500.0]], array_map(fn ($p) => [$p['source'], (float) $p['amount']], $receipt->applied_to));
        $this->assertSame(0.0, $older->fresh()->amountDue());
        $this->assertSame(0.0, $newer->fresh()->amountDue());
        $this->assertSame(-500.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(500.0, $receipt->advanceAmount());
        $this->assertStringContainsString($receipt->number(), (string) \App\Models\UdhaarTransaction::where('customer_id', $customer->id)->orderByDesc('id')->first()->note);
        $this->assertSame([$this->owner->id, 'cash'], [$older->payments()->latest('id')->first()->user_id, $older->payments()->latest('id')->first()->payment_method?->value ?? $older->payments()->latest('id')->first()->payment_method]);
    }

    public function test_receiving_needs_udhaar_access(): void
    {
        $customer = $this->customer();
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false]);
        $this->actingAs($staff);

        if ($staff->hasAccessTo('udhaar')) {
            $this->markTestSkipped('Default staff role includes udhaar.');
        }

        Livewire::test('customers.show', ['customer' => $customer])->call('openReceive')->assertForbidden();
    }
}
