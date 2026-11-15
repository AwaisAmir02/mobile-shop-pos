<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\Shipments;
use App\Couriers\Providers\LeopardsProvider;
use App\Couriers\Providers\PostExProvider;
use App\Enums\ConnectionStatus;
use App\Enums\CourierKey;
use App\Enums\FulfillmentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\CourierConnection;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Shipment;
use App\Models\Shop;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Courier booking against faked Leopards and PostEx APIs, shaped like their
 * published request/response formats. One fake closure per test (several
 * Http::fake calls in one test let the first stub win).
 */
class CourierBookingTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    /** What the fake Leopards says when tracked. */
    protected string $leopardsStatus = 'Pickup Request not Send';

    /** @var list<Request> */
    protected array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        $this->shop = Shop::create(['name' => 'Style Hub', 'couriers_enabled' => true, 'business_category' => 'social_seller']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id]);
        $this->actingAs($this->owner);

        $status = &$this->leopardsStatus;
        $sent = &$this->sent;

        Http::fake(function (Request $request) use (&$status, &$sent) {
            $sent[] = $request;
            $url = $request->url();
            $data = $request->data();

            if (str_contains($url, 'leopardscourier.com')) {
                if (($data['api_key'] ?? null) !== 'good-key') {
                    return Http::response(['status' => 0, 'error' => 'Invalid API Key or Password'], 200);
                }

                return match (true) {
                    str_contains($url, 'getAllCities') => Http::response(['status' => 1, 'city_list' => [
                        ['id' => 789, 'name' => 'Lahore', 'allow_as_destination' => '1'],
                        ['id' => 475, 'name' => 'Karachi', 'allow_as_destination' => '1'],
                    ]]),
                    str_contains($url, 'bookPacket') => Http::response(['status' => 1, 'track_number' => 'LE7512345678', 'slip_link' => 'https://merchantapi.leopardscourier.com/slip/LE7512345678.pdf']),
                    str_contains($url, 'trackBookedPacket') => Http::response(['status' => 1, 'packet_list' => [[
                        'track_number' => 'LE7512345678',
                        'booked_packet_status' => $status,
                        'Tracking Detail' => [['Status' => 'Consignment Booked', 'Activity_datetime' => '2026-11-15 10:00'], ['Status' => $status, 'Activity_datetime' => '2026-11-16 15:00']],
                    ]]]),
                    str_contains($url, 'cancelBookedPackets') => Http::response(['status' => 1]),
                    default => Http::response(['status' => 0, 'error' => 'unknown'], 404),
                };
            }

            if (str_contains($url, 'api.postex.pk')) {
                if (($request->header('token')[0] ?? null) !== 'good-token') {
                    return Http::response(['statusCode' => '401', 'statusMessage' => 'Unauthorized'], 401);
                }

                return match (true) {
                    str_contains($url, 'get-merchant-address') => Http::response(['statusCode' => '200', 'dist' => [
                        ['addressCode' => '001', 'address' => 'Shop 4, Main Market', 'cityName' => 'Lahore', 'addressType' => 'Pickup'],
                    ]]),
                    str_contains($url, 'get-operational-city') => Http::response(['statusCode' => '200', 'dist' => [['operationalCityName' => 'Lahore'], ['operationalCityName' => 'Karachi']]]),
                    str_contains($url, 'create-order') => Http::response(['statusCode' => '200', 'statusMessage' => 'ORDER HAS BEEN CREATED', 'dist' => ['trackingNumber' => 'CX-24147320009471']]),
                    str_contains($url, 'get-invoice') => Http::response('%PDF-1.4 fake label', 200, ['Content-Type' => 'application/pdf']),
                    str_contains($url, 'track-order') => Http::response(['statusCode' => '200', 'dist' => ['transactionStatus' => 'Out For Return', 'transactionStatusHistory' => [['transactionStatusMessage' => 'Booked'], ['transactionStatusMessage' => 'Out For Return']]]]),
                    str_contains($url, 'cancel-order') => Http::response(['statusCode' => '200']),
                    default => Http::response(['statusCode' => '404'], 404),
                };
            }

            return Http::response('unexpected host', 500);
        });
    }

    protected function connect(CourierKey $courier, array $credentials, array $settings = []): CourierConnection
    {
        return CourierConnection::create(['shop_id' => $this->shop->id, 'courier' => $courier, 'credentials' => $credentials, 'settings' => $settings, 'status' => ConnectionStatus::Connected, 'verified_at' => now()]);
    }

    /** A WhatsApp order accepted as a sale, cash on delivery. */
    protected function whatsappSale(float $price = 3500, float $paid = 0): array
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha Khan', 'phone' => '+923214445556']);
        $product = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Lawn Suit', 'price' => $price, 'stock_quantity' => 3, 'details' => []]);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0, 'custom_price' => null]], 0, $customer->id, $this->owner->id, now()->toDateString(), $paid);
        $contact = WhatsAppContact::create(['shop_id' => $this->shop->id, 'wa_id' => '923214445556', 'customer_id' => $customer->id, 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create([
            'shop_id' => $this->shop->id, 'whatsapp_contact_id' => $contact->id, 'customer_id' => $customer->id, 'status' => WhatsAppOrderStatus::Accepted,
            'delivery_name' => 'Ayesha Khan', 'delivery_phone' => '+923214445556', 'delivery_address' => 'House 12, Street 4, Model Town', 'delivery_area' => 'Lahore',
            'sale_id' => $sale->id, 'quoted_total' => $price,
        ]);

        return [$sale, $order, $product];
    }

    // ── Connecting ──────────────────────────────────────────────────────

    public function test_a_shop_connects_its_own_leopards_account_after_it_is_checked(): void
    {
        $settings = fn () => Livewire::test('settings.couriers')->call('edit', 'leopards');

        $settings()->set('fields.api_key', 'bad-key')->set('fields.api_password', 'secret')->call('save')
            ->assertHasErrors(['connect'])->assertSee('Leopards said: Invalid API Key or Password');
        $this->assertSame(0, CourierConnection::count());

        $settings()->set('fields.api_key', 'good-key')->set('fields.api_password', 'secret')->set('fields.environment', 'staging')->call('save')->assertHasNoErrors();

        $connection = CourierConnection::sole();
        $this->assertTrue($connection->isActive());
        $this->assertSame('staging', $connection->credential('environment'));
        $this->assertStringNotContainsString('secret', (string) DB::table('courier_connections')->value('credentials'));
        $this->assertStringContainsString('merchantapistaging.leopardscourier.com', $this->sent[array_key_last($this->sent)]->url());

        // The saved password is never sent back to the page, and a blank one keeps it.
        Livewire::test('settings.couriers')->call('edit', 'leopards')->assertSet('fields.api_password', '')->call('save')->assertHasNoErrors();
        $this->assertSame('secret', $connection->fresh()->credential('api_password'));
    }

    public function test_postex_reads_the_pickup_addresses_and_unready_couriers_say_why(): void
    {
        Livewire::test('settings.couriers')->call('edit', 'postex')->set('fields.token', 'good-token')->call('save')->assertHasNoErrors()
            ->assertSee('Shop 4, Main Market, Lahore')
            ->assertSee('TCS gives API access only after')->assertSee('Coming later');

        $this->assertSame('001', CourierConnection::sole()->setting('pickup_address_code'));
        Livewire::test('settings.couriers')->call('edit', 'tcs')->assertNotFound();
    }

    // ── Booking ─────────────────────────────────────────────────────────

    public function test_a_whatsapp_order_is_booked_with_its_delivery_details_and_cash_to_collect(): void
    {
        [$sale, $order] = $this->whatsappSale(3500);
        $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret', 'environment' => 'live']);

        Livewire::test('shipments.book', ['sale' => $sale])
            ->assertSet('name', 'Ayesha Khan')->assertSet('phone', '03214445556')->assertSet('cod', '3500')
            ->assertSet('city', '789')->assertSee('Filled in from the WhatsApp order')
            ->call('book')->assertHasNoErrors()->assertRedirect(route('shipments.index', ['focus' => 1]));

        $shipment = Shipment::sole();
        $this->assertSame('LE7512345678', $shipment->tracking_number);
        $this->assertSame($order->id, $shipment->whatsapp_order_id);
        $this->assertSame(ShipmentStatus::Booked, $shipment->status);

        $booking = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'bookPacket'));
        $this->assertSame(['789', '3500', $sale->invoiceNumber(), 'Ayesha Khan', '03214445556', 'self'], [
            (string) $booking['destination_city'], (string) $booking['booked_packet_collect_amount'], $booking['booked_packet_order_id'],
            $booking['consignment_name_eng'], $booking['consignment_phone'], $booking['origin_city'],
        ]);

        // One live parcel per sale.
        Livewire::test('shipments.book', ['sale' => $sale])->assertSee('Already booked');
        $this->get(route('sales.show', $sale))->assertSee('LE7512345678');
    }

    public function test_a_prepaid_order_collects_nothing_and_cod_can_not_exceed_what_is_owed(): void
    {
        [$sale] = $this->whatsappSale(3500, paid: 3500);
        $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);

        Livewire::test('shipments.book', ['sale' => $sale])->assertSet('cod', '0')
            ->set('cod', '500')->call('book')->assertHasErrors(['cod']);

        $this->assertSame(0, Shipment::count());
    }

    public function test_without_a_connected_courier_the_form_points_to_settings(): void
    {
        [$sale] = $this->whatsappSale();

        Livewire::test('shipments.book', ['sale' => $sale])->assertSee('Connect a courier first');
    }

    public function test_postex_books_with_the_pickup_address_and_serves_the_label_without_exposing_the_token(): void
    {
        [$sale] = $this->whatsappSale(4200);
        $connection = $this->connect(CourierKey::PostEx, ['token' => 'good-token'], ['pickup_address_code' => '001']);

        $shipment = Shipments::book($connection, $sale, ['name' => 'Ayesha Khan', 'phone' => '0321 4445556', 'address' => 'House 12, Street 4, Model Town', 'city' => 'Lahore', 'city_name' => 'Lahore',
            'cod' => 4200, 'weight_grams' => 700, 'pieces' => 1, 'description' => 'Lawn Suit'], $this->owner->id);

        $this->assertSame('CX-24147320009471', $shipment->tracking_number);
        $create = collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'v3/create-order'));
        $this->assertSame('good-token', $create->header('token')[0]);
        $this->assertSame(['001', 4200, 'Lahore', 'Normal', '03214445556'], [$create['pickupAddressCode'], $create['invoicePayment'], $create['cityName'], $create['orderType'], $create['customerPhone']]);

        $response = $this->get(route('shipments.label', $shipment))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringNotContainsString('good-token', $response->getContent());
    }

    public function test_the_leopards_label_opens_the_couriers_own_link(): void
    {
        [$sale] = $this->whatsappSale();
        $connection = $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);

        $this->get(route('shipments.label', $shipment))->assertRedirect('https://merchantapi.leopardscourier.com/slip/LE7512345678.pdf');
    }

    // ── Status, delivery and returns ────────────────────────────────────

    public function test_status_follows_the_courier_and_moves_the_whatsapp_order_along(): void
    {
        [$sale, $order] = $this->whatsappSale();
        $connection = $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);

        $this->leopardsStatus = 'Assigned to Courier';
        Shipments::refresh($shipment);
        $this->assertSame(ShipmentStatus::OutForDelivery, $shipment->fresh()->status);
        $this->assertSame(FulfillmentStatus::OutForDelivery, $order->fresh()->fulfillment_status);

        $this->leopardsStatus = 'Delivered';
        Livewire::test('shipments.index')->call('refresh', $shipment->id);
        $shipment->refresh();
        $this->assertSame(ShipmentStatus::Delivered, $shipment->status);
        $this->assertNotNull($shipment->delivered_at);
        $this->assertCount(2, $shipment->events);
        $this->assertSame(FulfillmentStatus::Delivered, $order->fresh()->fulfillment_status);
    }

    public function test_a_returned_parcel_waits_for_the_shop_then_cancels_the_sale_and_restocks(): void
    {
        [$sale, , $product] = $this->whatsappSale();
        $this->assertEquals(2, $product->fresh()->stock_quantity);
        $connection = $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);

        $this->leopardsStatus = 'Being Return';
        Shipments::refresh($shipment);
        $this->assertSame(ShipmentStatus::Returning, $shipment->fresh()->status);
        $this->assertFalse($shipment->fresh()->awaitsReturnReceipt());

        $this->leopardsStatus = 'Returned to shipper';
        Shipments::refresh($shipment);
        $this->assertTrue($shipment->fresh()->awaitsReturnReceipt());
        $this->assertEquals(2, $product->fresh()->stock_quantity, 'Nothing changes until the shop has the parcel back.');

        $this->get(route('shipments.index'))->assertSee('1 parcel came back');

        Livewire::test('shipments.index')->call('openReceive', $shipment->id)->assertSet('cancelSale', true)->call('receive');

        $this->assertNotNull($shipment->fresh()->return_received_at);
        $this->assertTrue(Sale::withoutGlobalScopes()->find($sale->id)->isCancelled());
        $this->assertEquals(3, $product->fresh()->stock_quantity);
    }

    public function test_staff_who_cannot_cancel_sales_can_still_receive_a_return_without_cancelling(): void
    {
        [$sale] = $this->whatsappSale();
        $connection = $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);
        $shipment->update(['status' => ShipmentStatus::Returned]);

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Packer', 'permissions' => ['sales']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $this->shop->id, 'role_id' => $role->id]));

        Livewire::test('shipments.index')->call('openReceive', $shipment->id)->assertSet('cancelSale', false)->call('receive')->assertOk();
        $this->assertFalse(Sale::withoutGlobalScopes()->find($sale->id)->isCancelled());
    }

    public function test_only_an_unpicked_parcel_can_be_cancelled_and_then_the_sale_can_be_booked_again(): void
    {
        [$sale] = $this->whatsappSale();
        $connection = $this->connect(CourierKey::Leopards, ['api_key' => 'good-key', 'api_password' => 'secret']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);

        Livewire::test('shipments.index')->call('cancel', $shipment->id);
        $this->assertSame(ShipmentStatus::Cancelled, $shipment->fresh()->status);
        $this->assertSame('LE7512345678', collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), 'cancelBookedPackets'))['cn_numbers']);

        Livewire::test('shipments.book', ['sale' => $sale])->assertDontSee('Already booked');

        $second = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);
        $second->update(['status' => ShipmentStatus::InTransit]);
        Livewire::test('shipments.index')->call('cancel', $second->id)->assertHasErrors(['shipment']);
    }

    public function test_the_scheduled_refresh_updates_every_shops_open_parcels(): void
    {
        [$sale] = $this->whatsappSale();
        $connection = $this->connect(CourierKey::PostEx, ['token' => 'good-token'], ['pickup_address_code' => '001']);
        $shipment = Shipments::book($connection, $sale, Shipments::prefill($sale) + ['city' => 'Lahore', 'city_name' => 'Lahore', 'weight_grams' => 500, 'pieces' => 1], $this->owner->id);

        auth()->logout();
        $this->artisan('couriers:refresh')->expectsOutput('Updated 1, failed 0.')->assertSuccessful();

        $this->assertSame(ShipmentStatus::Returning, $shipment->fresh()->status);
        $this->assertSame('Out For Return', $shipment->fresh()->courier_status);
    }

    public function test_couriers_words_map_onto_our_statuses(): void
    {
        foreach (['Pickup Request not Send' => 'booked', 'Consignment Booked' => 'booked', 'Arrived at Station' => 'in_transit', 'Assigned to Courier' => 'out_for_delivery',
            'Delivered' => 'delivered', 'Being Return' => 'returning', 'Returned to shipper' => 'returned', 'Cancelled' => 'cancelled'] as $words => $status) {
            $this->assertSame($status, LeopardsProvider::mapStatus($words)->value, $words);
        }

        foreach (['Unbooked' => 'booked', 'PostEx WareHouse' => 'in_transit', 'Out For Delivery' => 'out_for_delivery', 'Delivered' => 'delivered',
            'Out For Return' => 'returning', 'Returned' => 'returned', 'Expired' => 'cancelled', 'Attempted' => 'in_transit'] as $words => $status) {
            $this->assertSame($status, PostExProvider::mapStatus($words)->value, $words);
        }
    }

    // ── Who sees what ───────────────────────────────────────────────────

    public function test_without_the_feature_nothing_is_reachable(): void
    {
        [$sale] = $this->whatsappSale();
        $this->shop->update(['couriers_enabled' => false]);
        $this->owner->unsetRelation('shop');

        $this->get(route('shipments.index'))->assertNotFound();
        $this->get(route('shipments.book', $sale))->assertNotFound();
        $this->get(route('sales.show', $sale))->assertDontSee('Book courier');
        $this->get(route('profile'))->assertDontSee(route('shipments.index'));
    }

    public function test_another_shops_parcel_is_out_of_reach(): void
    {
        $other = Shop::create(['name' => 'Other', 'couriers_enabled' => true]);
        $otherConnection = CourierConnection::create(['shop_id' => $other->id, 'courier' => CourierKey::Leopards, 'credentials' => ['api_key' => 'good-key'], 'status' => ConnectionStatus::Connected]);
        $foreign = Shipment::create(['shop_id' => $other->id, 'courier_connection_id' => $otherConnection->id, 'courier' => CourierKey::Leopards, 'reference' => 'INV-9', 'consignee_name' => 'X', 'consignee_phone' => '03000000000',
            'consignee_address' => 'Somewhere far', 'city' => '789', 'city_name' => 'Lahore', 'weight_grams' => 500, 'description' => 'x', 'tracking_number' => 'LE0000000001', 'label_url' => 'https://example.com/x.pdf', 'status' => ShipmentStatus::Booked]);

        $this->get(route('shipments.label', $foreign))->assertNotFound();
        $this->get(route('shipments.index'))->assertDontSee('LE0000000001');
    }

    public function test_the_sidebar_and_integrations_show_couriers_to_shops_that_have_it(): void
    {
        $this->get(route('profile'))->assertSee(route('shipments.index'));
        $this->get(route('settings.index', ['tab' => 'integrations', 'section' => 'couriers']))->assertOk()->assertSee('Book parcels with your own courier accounts');
    }
}
