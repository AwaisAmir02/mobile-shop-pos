<?php

namespace Tests\Feature;

use App\Actions\CustomerPayments;
use App\Actions\SalePaymentLinks;
use App\Enums\ShipmentStatus;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\SalePaymentLink;
use App\Models\Shop;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O7: a JazzCash link that pays one order, in full or an advance, and only
 * that order; the customer's other dues are never touched.
 */
class OrderPaymentLinksTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop();
        $this->jazzcash();
    }

    public function test_the_link_pays_this_order_only_not_the_customers_older_dues(): void
    {
        $older = $this->codSale(1, 0);
        $order = $this->codSale(1, 250);
        $link = SalePaymentLinks::create($order, null, $this->owner->id);
        auth()->logout();

        $this->get($link->url())->assertOk()->assertSee('Order '.$order->invoiceNumber())->assertSee('Rs 3,750.00')->assertSee('Pay with JazzCash');
        $form = $this->post(route('order-payment.pay', $link->token))->assertOk()->assertSee('pp_SecureHash', false);
        $payment = CustomerPayment::sole();
        $form->assertSee('value="375000"', false);

        $this->post(route('payments.jazzcash.return'), $this->jazzcashResult($payment))->assertRedirect(route('order-payment.show', $link->token));

        $this->assertSame([CustomerPayment::PAID, 0.0, 3500.0], [$payment->fresh()->status, $order->fresh()->amountDue(), $older->fresh()->amountDue()]);
        // MySQL's JSON column keeps its own key order.
        $this->assertEquals([['source' => 'sale', 'id' => $order->id, 'amount' => 3750]], $payment->fresh()->applied_to);
        $this->get($link->url())->assertSee('Nothing to pay');
    }

    public function test_an_advance_link_asks_for_part_and_the_rest_stays_cash_on_delivery(): void
    {
        $order = $this->codSale(1, 250);
        $link = SalePaymentLinks::create($order, 250, $this->owner->id);

        $this->assertSame(250.0, SalePaymentLinks::amountDue($link));
        $payment = SalePaymentLinks::start($link, \App\Models\PaymentGatewayConnection::sole());
        CustomerPayments::completeJazzCash($this->jazzcashResult($payment));

        $this->assertSame(3500.0, $order->fresh()->amountDue());
        $this->assertSame(3500.0, $this->parcel($order->fresh())->cod_amount + 0.0);
    }

    public function test_a_replaced_or_courier_held_order_cant_be_paid_and_a_forged_result_pays_nothing(): void
    {
        $order = $this->codSale(1, 250);
        $first = SalePaymentLinks::create($order, null, $this->owner->id);
        $second = SalePaymentLinks::create($order, null, $this->owner->id);

        $this->post(route('order-payment.pay', $first->token))->assertRedirect(route('order-payment.show', $first->token))->assertSessionHasErrors('amount');

        $payment = SalePaymentLinks::start($second, \App\Models\PaymentGatewayConnection::sole());
        $this->assertNull(CustomerPayments::completeJazzCash($this->jazzcashResult($payment, salt: 'wrong-salt')));
        $this->assertSame(3750.0, $order->fresh()->amountDue());

        $this->parcel($order->fresh(), ShipmentStatus::InTransit);
        $this->expectException(ValidationException::class);
        SalePaymentLinks::start($second->fresh(), \App\Models\PaymentGatewayConnection::sole());
    }

    public function test_the_sale_page_makes_a_link_for_online_sellers_only(): void
    {
        $order = $this->codSale(1, 250);

        $this->get(route('sales.show', $order))->assertSee('Make payment link');
        Livewire::test('sales.payment-link', ['saleId' => $order->id])->set('linkAmount', '250')->call('create')->assertHasNoErrors()->assertSee('JazzCash link for Rs 250.00');
        $this->assertSame('250.00', SalePaymentLink::sole()->amount);

        $retail = Shop::create(['name' => 'Corner', 'business_category' => 'clothing']);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $this->assertFalse(SalePaymentLinks::available($retail));
    }

    public function test_an_unknown_link_is_not_found(): void
    {
        $this->get(route('order-payment.show', str_repeat('a', 40)))->assertNotFound();
        $this->get(route('order-payment.show', 'short'))->assertNotFound();
        $this->assertSame(0.0, CustomerDuesStatement::for($this->customerOf($this->codSale()))->total() - 3750.0);
    }
}
