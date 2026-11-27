<?php

namespace Tests\Feature;

use App\Actions\Contra;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Contra (W11): a customer who is also a vendor; bills set off against what the
 * shop owes them, never touching udhaar.
 */
class ContraTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_contra_sets_bills_off_against_what_the_shop_owes_and_never_touches_udhaar(): void
    {
        $this->useShop('trader');
        $this->stock(10, 1000, 'unpaid');   // shop owes the vendor 10,000
        $customer = $this->customer(['vendor_id' => $this->vendor->id]);
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 2000, 'transaction_date' => '2026-11-01']);
        $sale = $this->sell($customer, 4, 0);   // they owe 4 x 1,500 = 6,000 on bills
        $flow = $this->netMoneyMoved();

        $this->assertSame(['customer_owes' => 6000.0, 'vendor_owed' => 10000.0, 'most' => 6000.0], Contra::position($customer->fresh()));

        try {
            Contra::settle($customer, 6500, null, $this->owner->id);
            $this->fail('More than the smaller side must be refused.');
        } catch (ValidationException) {
        }

        Livewire::test('customers.show', ['customer' => $customer])->assertSee('Contra with')->call('openContra')->set('contraAmount', '5000')->call('settleContra')->assertHasNoErrors();

        $this->assertSame(1000.0, $sale->fresh()->amountDue());
        $this->assertSame(5000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(2000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame($flow, $this->netMoneyMoved());

        Contra::cancel(\App\Models\ContraSettlement::sole(), $this->owner->id);
        $this->assertSame(6000.0, $sale->fresh()->amountDue());
        $this->assertSame(10000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame($flow, $this->netMoneyMoved());
    }

    public function test_the_customer_form_links_a_vendor_once(): void
    {
        $this->useShop('trader');
        $customer = $this->customer();
        $other = $this->customer(['name' => 'Other']);

        Livewire::test('customers.index')->call('openEdit', $customer->id)->set('linkedVendorId', (string) $this->vendor->id)->call('save')->assertHasNoErrors();
        $this->assertSame($this->vendor->id, $customer->fresh()->vendor_id);

        Livewire::test('customers.index')->call('openEdit', $other->id)->set('linkedVendorId', (string) $this->vendor->id)->call('save')->assertHasErrors('linkedVendorId');
        $this->get(route('vendors.show', $this->vendor))->assertSee('Also a customer');
    }
}
