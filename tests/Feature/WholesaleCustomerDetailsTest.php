<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Business customers (W2): their details, and the invoice printing them with
 * the balance before and after the bill.
 */
class WholesaleCustomerDetailsTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_business_details_are_saved_and_printed_with_balances_on_the_invoice(): void
    {
        Livewire::test('customers.index')
            ->call('openCreate')
            ->set('name', 'Imran')
            ->set('phone', '03001112223')
            ->set('business_name', 'Imran Kiryana')
            ->set('contact_person', 'Imran Ali')
            ->set('phone_2', '03214445556')
            ->set('area', 'Shah Alam')
            ->set('ntn', '1234567-8')
            ->set('strn', '32-77-8761-234-12')
            ->set('credit_limit', '50000')
            ->set('payment_terms_days', '15')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame(['Imran Kiryana', 'Shah Alam', '1234567-8', 15, '50000.00'], [$customer->business_name, $customer->area, $customer->ntn, $customer->payment_terms_days, $customer->credit_limit]);
        $this->assertSame('Imran Kiryana (Imran)', $customer->displayName());

        $product = $this->product();
        $earlier = $this->sell($customer, $product, 10, 400, '2026-11-01');
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-11-05']);
        $sale = $this->sell($customer, $product, 5, 100, null, ['due_date' => '2026-12-05']);

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items', 'shop', 'user', 'payments'])])->render();

        $this->assertStringContainsString('Imran Kiryana', $html);
        $this->assertStringContainsString('NTN 1234567-8', $html);
        $this->assertStringContainsString('Shah Alam', $html);
        $this->assertStringContainsString('Payment due by 05 Dec 2026', $html);
        // Sold straight through CreateSale, so at the normal Rs 100. Earlier bill 1,000 - 400 = 600
        // + udhaar 1,000 = 1,600 before; this bill 500 - 100 = 400; 2,000 in all.
        $this->assertStringContainsString('Previous balance', $html);
        $this->assertStringContainsString('Rs 1,600.00', $html);
        $this->assertStringContainsString('Rs 2,000.00', $html);
        $this->assertNotNull($earlier);
    }
}
