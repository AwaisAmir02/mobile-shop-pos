<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\CreateSale;
use App\Actions\SaleReturns;
use App\Actions\StoreOrders;
use App\Enums\SaleSource;
use App\Enums\ShipmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Ledger\Builders\SaleBuilder;
use App\Ledger\ChartOfAccounts;
use App\Ledger\Line;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDelivery;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\ReportQueries\ProfitReport;
use App\ReportQueries\SalesReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O1 and O2: an online order rung up from a message or a call records where it
 * came from, who it goes to and what they pay for delivery; the delivery charge
 * is part of the sale's total, revenue and ledger. Every other shop's register,
 * WhatsApp and store acceptance work exactly as before.
 */
class OnlineOrdersTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop();
    }

    protected function register()
    {
        return Livewire::test('sales.create')->call('addToCart', $this->suit->id);
    }

    public function test_a_cash_on_delivery_order_records_its_channel_delivery_charge_and_buyer(): void
    {
        $register = $this->register()
            ->assertSet('channel', SaleSource::Social->value)
            ->assertSet('withDelivery', true)
            ->set('delivery', $this->deliveryTo())
            ->set('deliveryCharge', '250')
            ->set('amountPaidNow', '0')
            ->assertSee('Rs 3,750.00')
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame([SaleSource::Social, '3500.00', '250.00', '3750.00'], [$sale->source, $sale->subtotal, $sale->delivery_charge, $sale->total]);
        $this->assertSame(3750.0, $sale->amountDue());

        // The buyer became a customer from the delivery details, and the address is kept with the sale.
        $customer = Customer::sole();
        $this->assertSame(['Ayesha Khan', '0321 4445556'], [$customer->name, $customer->phone]);
        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame(['Ayesha Khan', '923214445556', 'Lahore'], [$sale->delivery->name, $sale->delivery->phone_key, $sale->delivery->city]);
        $register->assertRedirect(route('sales.show', $sale));
    }

    public function test_a_second_order_from_the_same_phone_is_the_same_customer(): void
    {
        $this->register()->set('delivery', $this->deliveryTo('0321 4445556'))->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();
        $this->register()->set('delivery', $this->deliveryTo('+92 321 4445556', 'Ayesha'))->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();

        $this->assertSame(1, Customer::count());
        $this->assertSame(2, Sale::where('customer_id', Customer::sole()->id)->count());
    }

    public function test_delivery_details_are_checked_and_a_parcel_booked_on_the_portal_is_recorded(): void
    {
        $this->register()->set('delivery', ['name' => '', 'phone' => '12', 'address' => 'short', 'city' => ''])->call('completeSale')
            ->assertHasErrors(['delivery.name', 'delivery.phone', 'delivery.address']);

        $this->register()
            ->set('delivery', $this->deliveryTo())
            ->set('deliveryCharge', '200')
            ->set('amountPaidNow', '0')
            ->set('parcelCourier', 'other')
            ->set('parcelTracking', 'BX-778899')
            ->call('completeSale')
            ->assertHasErrors(['parcelCourierName'])
            ->set('parcelCourierName', 'BlueEx')
            ->call('completeSale')
            ->assertHasNoErrors();

        $shipment = Shipment::sole();
        $this->assertTrue($shipment->isManual());
        $this->assertTrue($shipment->cod_tracked);
        $this->assertSame(['BlueEx', 'BX-778899', '3700.00', ShipmentStatus::Booked, 'Ayesha Khan', '03214445556'], [$shipment->courierLabel(), $shipment->tracking_number, $shipment->cod_amount, $shipment->status, $shipment->consignee_name, $shipment->consignee_phone]);
    }

    public function test_an_order_paid_in_advance_needs_no_customer_and_an_in_person_sale_needs_no_delivery(): void
    {
        $this->register()->set('delivery', $this->deliveryTo())->set('deliveryCharge', '250')->call('completeSale')->assertHasNoErrors();
        $this->assertSame(['3750.00', 3750.0, null], [Sale::sole()->total, Sale::sole()->amountPaid(), Sale::sole()->customer_id]);

        $this->register()->set('channel', SaleSource::InStore->value)->set('withDelivery', false)->set('deliveryCharge', '250')->call('completeSale')->assertHasNoErrors();
        $walkIn = Sale::latest('id')->first();
        $this->assertSame([SaleSource::InStore, '0.00', '3500.00'], [$walkIn->source, $walkIn->delivery_charge, $walkIn->total]);
        $this->assertNull($walkIn->delivery);
    }

    public function test_a_retail_register_is_unchanged(): void
    {
        $retail = Shop::create(['name' => 'Corner Store', 'business_category' => 'clothing']);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $shirt = Product::create(['shop_id' => $retail->id, 'type' => 'accessory', 'name' => 'Shirt', 'price' => 1000, 'stock_quantity' => 5, 'details' => []]);

        Livewire::test('sales.create')
            ->assertViewHas('takesDelivery', false)
            ->assertDontSee('data-online-delivery', false)
            ->assertSee('Udhaar')
            ->call('addToCart', $shirt->id)
            ->set('deliveryCharge', '250')
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::withoutGlobalScopes()->where('shop_id', $retail->id)->sole();
        $this->assertSame([SaleSource::InStore, '0.00', '1000.00'], [$sale->source, $sale->delivery_charge, $sale->total]);
        $this->assertSame(0, SaleDelivery::count());
    }

    public function test_the_delivery_charge_is_revenue_on_the_sale_page_the_invoice_the_reports_and_the_ledger(): void
    {
        $sale = $this->codSale(1, 250);

        $this->get(route('sales.show', $sale))->assertSee('Delivery charge')->assertSee('Rs 250.00')->assertSee('Model Town');
        $this->assertStringContainsString('Delivery charge', view('pdf.invoice', ['sale' => $sale->load(['items', 'shop', 'user', 'payments'])])->render());

        $profit = (new ProfitReport('2026-11-01', '2026-11-30'))->totals();
        $this->assertSame([3750.0, 250.0, 1750.0], [$profit['revenue'], $profit['deliveryCharges'], $profit['grossProfit']]);
        $this->assertSame(3750.0, (new SalesReport)->totalRevenue());

        $lines = collect((new SaleBuilder)->lines($sale->fresh()))->mapWithKeys(fn (Line $line) => [$line->account => ($line->toArray()['paise'] ?? null)]);
        $this->assertSame(-350000, $lines[ChartOfAccounts::SALES]);
        $this->assertSame(-25000, $lines[ChartOfAccounts::DELIVERY_INCOME]);
        $this->assertSame(0, collect((new SaleBuilder)->lines($sale->fresh()))->sum(fn (Line $line) => $line->toArray()['paise']));
    }

    public function test_editing_the_goods_keeps_the_delivery_charge_and_a_full_return_gives_back_the_goods_only(): void
    {
        $sale = $this->codSale(2, 250);

        \App\Actions\UpdateSale::handle($sale->fresh(), [['product_id' => $this->suit->id, 'product_name' => 'Lawn Suit', 'product_type' => $this->suit->type, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, $sale->customer_id, $this->owner->id, now()->toDateString());
        $this->assertSame(['3500.00', '250.00', '3750.00'], [$sale->fresh()->subtotal, $sale->fresh()->delivery_charge, $sale->fresh()->total]);

        $return = SaleReturns::create($sale->fresh(), ['p'.$this->suit->id => ['quantity' => '1']], null, null, null, $this->owner->id);
        $this->assertSame('3500.00', $return->value_total);
        $this->assertSame(250.0, $sale->fresh()->amountDue());
    }

    public function test_a_whatsapp_order_is_accepted_with_a_delivery_charge(): void
    {
        $this->shop->update(['whatsapp_ordering_enabled' => true]);
        $this->actingAs($this->owner->fresh());
        $contact = WhatsAppContact::create(['shop_id' => $this->shop->id, 'wa_id' => '923214445556', 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $this->shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(),
            'delivery_name' => 'Ayesha', 'delivery_phone' => '+923214445556', 'delivery_address' => 'House 12, Model Town', 'quoted_total' => 3500]);
        WhatsAppOrderItem::create(['shop_id' => $this->shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $this->suit->id, 'product_name' => 'Lawn Suit', 'quantity' => 1, 'unit' => 'piece', 'quoted_unit_price' => 3500]);
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha', 'phone' => '03214445556']);

        Livewire::test('whatsapp-orders.show', ['order' => $order])
            ->set('customerId', (string) $customer->id)
            ->set('deliveryCharge', '200')
            ->set('amountPaid', '0')
            ->assertSee('Rs 3,700.00')
            ->call('accept')
            ->assertHasNoErrors();

        $this->assertSame(['3700.00', '200.00', SaleSource::WhatsApp], [Sale::sole()->total, Sale::sole()->delivery_charge, Sale::sole()->source]);
    }

    public function test_a_store_orders_shipping_is_part_of_an_online_sellers_sale_but_not_a_retail_shops(): void
    {
        foreach (['social_seller' => ['3750.00', '250.00'], 'clothing' => ['3500.00', '0.00']] as $category => [$total, $delivery]) {
            $shop = Shop::create(['name' => 'Store '.$category, 'business_category' => $category, 'online_store_enabled' => true]);
            $owner = \App\Models\User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
            $this->actingAs($owner);
            $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Suit', 'price' => 3500, 'stock_quantity' => 5, 'details' => []]);
            $connection = \App\Actions\StoreConnections::create($shop->id, 'shop-'.$category.'.pk', null)['connection'];
            $order = StoreOrders::receive($connection, [
                'external_order_id' => 'W-1', 'placed_at' => now()->toIso8601String(),
                'customer' => ['name' => 'Sana', 'phone' => '+923001112223', 'address' => 'House 9', 'area' => 'Gulberg'],
                'payment' => ['method' => 'cod', 'amount_paid' => '0.00'], 'shipping_amount' => '250.00', 'discount_amount' => '0.00',
                'items' => [['product_id' => $product->id, 'name' => 'Suit', 'quantity' => 1, 'unit_price' => '3500.00']],
            ], 'evt-'.$category);
            StoreOrders::mapItem($order->items()->first(), $product);
            $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana']);

            $sale = StoreOrders::accept($order, [], 0, $customer->id, 0, $owner->id);

            $this->assertSame([$total, $delivery], [$sale->total, $sale->delivery_charge], $category);
        }
    }
}
