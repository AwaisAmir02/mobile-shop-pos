<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shop;
use App\Models\UdhaarTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalePaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function createProduct(Shop $shop, float $price = 10000, int $stock = 10): Product
    {
        return Product::create([
            'shop_id' => $shop->id,
            'type' => 'accessory',
            'name' => 'Cable',
            'price' => $price,
            'stock_quantity' => $stock,
            'details' => [],
        ]);
    }

    // ── Completing a sale with partial / zero / full payment ──────────

    public function test_a_fully_paid_sale_needs_no_customer_and_shows_no_due_balance(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = $this->createProduct($shop, 500);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertNull($sale->customer_id);
        $this->assertEquals(500, $sale->amountPaid());
        $this->assertEquals(0, $sale->amountDue());
        $this->assertSame('paid', $sale->paymentStatus());
    }

    public function test_completing_a_sale_with_partial_payment_requires_a_customer_and_records_the_right_due_amount(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = $this->createProduct($shop, 10000);

        $this->actingAs($owner);

        // Without a customer, a partial payment is rejected outright.
        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '4000')
            ->call('completeSale')
            ->assertHasErrors(['customerId']);

        $this->assertSame(0, Sale::count());

        // With a customer selected, it succeeds and records the exact due amount.
        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '4000')
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertEquals(10000, $sale->total);
        $this->assertEquals(4000, $sale->amountPaid());
        $this->assertEquals(6000, $sale->amountDue());
        $this->assertSame('partial', $sale->paymentStatus());
    }

    public function test_completing_a_sale_with_zero_payment_is_full_credit_and_also_requires_a_customer(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = $this->createProduct($shop, 3000);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '0')
            ->call('completeSale')
            ->assertHasErrors(['customerId']);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '0')
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::firstOrFail();
        $this->assertEquals(0, $sale->amountPaid());
        $this->assertEquals(3000, $sale->amountDue());
        $this->assertSame('unpaid', $sale->paymentStatus());
    }

    public function test_the_amount_paid_now_cannot_exceed_the_sale_total(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $product = $this->createProduct($shop, 1000);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '1500')
            ->call('completeSale')
            ->assertHasErrors(['amountPaidNow']);

        $this->assertSame(0, Sale::count());
    }

    // ── Recording later payments against a sale ────────────────────────

    public function test_recording_payments_against_a_sale_reduces_its_due_balance_with_exact_math(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = $this->createProduct($shop, 10000);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '4000')
            ->call('completeSale');

        $sale = Sale::firstOrFail();
        $this->assertEquals(6000, $sale->amountDue());

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->set('paymentAmount', '3000')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $sale->refresh();
        $this->assertEquals(7000, $sale->amountPaid());
        $this->assertEquals(3000, $sale->amountDue());
        $this->assertSame('partial', $sale->paymentStatus());

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->set('paymentAmount', '3000')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $sale->refresh();
        $this->assertEquals(10000, $sale->amountPaid());
        $this->assertEquals(0, $sale->amountDue());
        $this->assertSame('paid', $sale->paymentStatus());
        $this->assertSame(3, SalePayment::where('sale_id', $sale->id)->count());
    }

    public function test_a_payment_cannot_exceed_what_is_still_due(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = $this->createProduct($shop, 1000);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '200')
            ->call('completeSale');

        $sale = Sale::firstOrFail();

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->set('paymentAmount', '9999')
            ->call('submitPayment')
            ->assertHasErrors(['paymentAmount']);

        $this->assertEquals(800, $sale->fresh()->amountDue());
    }

    // ── Sales-outstanding vs Udhaar never interfere ─────────────────────

    public function test_a_customers_sales_outstanding_and_udhaar_balances_never_interfere(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan']);
        $product = $this->createProduct($shop, 5000);

        $this->actingAs($owner);

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('amountPaidNow', '2000')
            ->call('completeSale');

        UdhaarTransaction::create([
            'shop_id' => $shop->id,
            'customer_id' => $customer->id,
            'type' => 'given',
            'amount' => 9000,
            'transaction_date' => '2026-08-01',
        ]);

        $customer->refresh();
        $this->assertEquals(3000, $customer->salesOutstandingBalance());
        $this->assertEquals(9000, $customer->udhaarBalance());

        Livewire::test('customers.show', ['customer' => $customer])
            ->assertSee('Rs 3,000.00')
            ->assertSee('Rs 9,000.00');
    }

    // ── Tenant isolation ──────────────────────────────────────────────

    public function test_sales_outstanding_totals_are_isolated_between_shops(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerA = Customer::create(['shop_id' => $shopA->id, 'name' => 'Customer A']);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B']);

        $saleA = Sale::create(['shop_id' => $shopA->id, 'customer_id' => $customerA->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);
        SalePayment::create(['shop_id' => $shopA->id, 'sale_id' => $saleA->id, 'amount' => 400, 'payment_date' => '2026-08-01']);

        $saleB = Sale::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'subtotal' => 9999, 'discount_amount' => 0, 'total' => 9999]);
        SalePayment::create(['shop_id' => $shopB->id, 'sale_id' => $saleB->id, 'amount' => 0, 'payment_date' => '2026-08-01']);

        $this->actingAs($ownerA);

        Livewire::test('sales.history')->assertViewHas('totalOutstanding', 600.0);

        $this->assertSame(1, SalePayment::count());
    }

    public function test_a_tampered_sale_id_from_another_shop_cannot_receive_a_recorded_payment(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $customerB = Customer::create(['shop_id' => $shopB->id, 'name' => 'Customer B']);
        $saleB = Sale::create(['shop_id' => $shopB->id, 'customer_id' => $customerB->id, 'subtotal' => 1000, 'discount_amount' => 0, 'total' => 1000]);
        SalePayment::create(['shop_id' => $shopB->id, 'sale_id' => $saleB->id, 'amount' => 0, 'payment_date' => '2026-08-01']);

        $this->actingAs($ownerA);

        // Sale::findOrFail() inside submitPayment() is subject to the shop
        // global scope, so a shop-A actor can never resolve shop B's sale —
        // it correctly raises a 404 rather than silently succeeding.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test('sales.history')
            ->set('payingId', $saleB->id)
            ->set('paymentAmount', '500')
            ->call('submitPayment');
    }
}
