<?php

namespace Tests\Feature;

use App\Actions\SalesOrders;
use App\Actions\StoreConnections;
use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\StockHoldKind;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\WhatsApp\WhatsAppChatbot;
use App\Support\StockAvailability;
use App\Support\StoreAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\OnlineShopFixtures;
use Tests\TestCase;

/**
 * O3: one "free to sell" figure for the chatbot, the online store, the
 * register and sales orders, so two buyers on two channels can't both be
 * promised the last piece.
 */
class StockAvailabilityTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop('social_seller', ['whatsapp_ordering_enabled' => true, 'online_store_enabled' => true]);
    }

    /** A confirmed WhatsApp order waiting for the shop's decision. */
    protected function pendingWhatsAppOrder(int|float $quantity, string $waId = '923001112223'): WhatsAppOrder
    {
        $contact = WhatsAppContact::create(['shop_id' => $this->shop->id, 'wa_id' => $waId, 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $this->shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'quoted_total' => 0]);
        WhatsAppOrderItem::create(['shop_id' => $this->shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $this->suit->id, 'product_name' => 'Lawn Suit', 'quantity' => $quantity, 'unit' => 'piece', 'quoted_unit_price' => 3500]);

        return $order;
    }

    public function test_every_open_order_holds_stock_and_an_order_being_decided_leaves_itself_out(): void
    {
        $order = $this->pendingWhatsAppOrder(3);
        $this->assertSame(7, StockAvailability::freeFor($this->suit));
        $this->assertSame([$this->suit->id => 3], StockAvailability::held([$this->suit->id]));
        $this->assertSame(10, StockAvailability::freeFor($this->suit, [StockHoldKind::WhatsAppOrder, $order->id]));

        $order->update(['status' => WhatsAppOrderStatus::Rejected]);
        $this->assertSame(10, StockAvailability::freeFor($this->suit));
    }

    public function test_the_store_publishes_the_same_figure_less_its_buffer(): void
    {
        $connection = StoreConnections::create($this->shop->id, 'stylehub.pk', null)['connection'];
        $connection->update(['safety_buffer' => 2]);
        $this->pendingWhatsAppOrder(3);

        $this->assertSame([$this->suit->id => 5], StoreAvailability::for($connection->fresh(), collect([$this->suit->fresh()])));
    }

    public function test_the_chatbot_never_offers_what_another_confirmed_order_holds(): void
    {
        $this->pendingWhatsAppOrder(9);
        $connection = ShopChannelConnection::create(['shop_id' => $this->shop->id, 'provider' => ChannelProvider::WhatsApp, 'external_id' => '100200300', 'waba_id' => '900', 'display_name' => 'Style Hub', 'access_token' => 'token', 'status' => ConnectionStatus::Connected, 'connected_at' => now()]);
        $bot = new WhatsAppChatbot($connection);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200)]);

        $bot->handleInbound(['from' => '923009998887', 'id' => 'wamid.1', 'type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => 'qty:'.$this->suit->id.':2', 'title' => 'x']]], null);

        $this->assertSame(0, WhatsAppOrderItem::whereHas('order', fn ($q) => $q->where('status', WhatsAppOrderStatus::Collecting->value))->count());
        $this->assertTrue(collect(Http::recorded())->contains(fn ($pair) => str_contains(json_encode($pair[0]->data()), 'only 1 of Lawn Suit available')));
    }

    public function test_an_online_sellers_register_refuses_stock_held_for_pending_orders(): void
    {
        $this->pendingWhatsAppOrder(9);

        Livewire::test('sales.create')
            ->call('addToCart', $this->suit->id)
            ->set("cart.{$this->suit->id}.quantity", 2)
            ->set('withDelivery', false)
            ->call('completeSale')
            ->assertHasErrors(["cart.{$this->suit->id}.quantity"])
            ->assertSee('Only 1 of Lawn Suit is free to sell; 9 is held for pending WhatsApp orders.');

        $this->assertSame(0, Sale::count());
    }

    public function test_a_retail_register_still_sells_what_pending_orders_hold(): void
    {
        $retail = \App\Models\Shop::create(['name' => 'Corner', 'business_category' => 'clothing', 'whatsapp_ordering_enabled' => true]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $shirt = Product::create(['shop_id' => $retail->id, 'type' => 'accessory', 'name' => 'Shirt', 'price' => 1000, 'stock_quantity' => 2, 'details' => []]);
        $contact = WhatsAppContact::create(['shop_id' => $retail->id, 'wa_id' => '923001112223', 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $retail->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'quoted_total' => 0]);
        WhatsAppOrderItem::create(['shop_id' => $retail->id, 'whatsapp_order_id' => $order->id, 'product_id' => $shirt->id, 'product_name' => 'Shirt', 'quantity' => 2, 'unit' => 'piece', 'quoted_unit_price' => 1000]);

        Livewire::test('sales.create')->call('addToCart', $shirt->id)->set("cart.{$shirt->id}.quantity", 2)->call('completeSale')->assertHasNoErrors();

        $this->assertSame(1, Sale::withoutGlobalScopes()->where('shop_id', $retail->id)->count());
    }

    public function test_a_distributors_booking_and_counter_still_refuse_what_booked_orders_hold_with_the_same_message(): void
    {
        $distributor = \App\Models\Shop::create(['name' => 'Dist', 'business_category' => 'distributor']);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $distributor->id, 'is_owner' => true]));
        $tea = Product::create(['shop_id' => $distributor->id, 'type' => 'accessory', 'name' => 'Tea', 'price' => 1500, 'stock_quantity' => 10, 'details' => []]);
        $customer = Customer::create(['shop_id' => $distributor->id, 'name' => 'Bilal']);
        SalesOrders::save(null, $customer->id, [['product_id' => $tea->id, 'quantity' => 4, 'unit_price' => 1500]], null, null, null, auth()->id());

        $this->assertSame(6, SalesOrders::available($tea->fresh()));
        $this->assertSame(10, SalesOrders::available($tea->fresh(), \App\Models\SalesOrder::sole()->id));

        Livewire::test('sales.create')->call('addToCart', $tea->id)->set("cart.{$tea->id}.quantity", 7)->call('completeSale')
            ->assertHasErrors(["cart.{$tea->id}.quantity"])
            ->assertSee('Only 6 of Tea is free to sell; 4 is held for booked orders.');
    }
}
