<?php

namespace Tests\Feature;

use App\Actions\UpdateWhatsAppOrderFulfillment;
use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\CustomerNotification;
use App\Models\CustomerReview;
use App\Models\Repair;
use App\Models\RestockSubscription;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppEngagementTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    protected function acceptedOrder($shop, $connection, ?string $lastInbound = 'now'): WhatsAppOrder
    {
        $contact = WhatsAppContact::create([
            'shop_id' => $shop->id,
            'shop_channel_connection_id' => $connection->id,
            'wa_id' => $this->customer,
            'profile_name' => 'Ali',
            'last_inbound_at' => $lastInbound ? now() : null,
        ]);

        return WhatsAppOrder::create([
            'shop_id' => $shop->id,
            'whatsapp_contact_id' => $contact->id,
            'status' => WhatsAppOrderStatus::Accepted,
            'submitted_at' => now(),
            'decided_at' => now(),
            'quoted_total' => 1500,
        ]);
    }

    public function test_fulfillment_moves_forward_only_and_messages_the_customer_each_step(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $order = $this->acceptedOrder($shop, $connection);
        $this->actingAs($owner);

        Livewire::test('whatsapp-orders.show', ['order' => $order])->call('setFulfillment', 'packed')->assertHasNoErrors();
        $this->assertStringContainsString('has been packed', collect($this->sentBodies())->last()['text']['body']);

        Livewire::test('whatsapp-orders.show', ['order' => $order])->call('setFulfillment', 'out_for_delivery')->assertHasNoErrors();
        $this->assertStringContainsString('out for delivery', collect($this->sentBodies())->last()['text']['body']);

        Livewire::test('whatsapp-orders.show', ['order' => $order])->call('setFulfillment', 'packed')->assertHasErrors(['fulfillment']);

        Livewire::test('whatsapp-orders.show', ['order' => $order])->call('setFulfillment', 'delivered')->assertHasNoErrors();
        $order->refresh();
        $this->assertSame(FulfillmentStatus::Delivered, $order->fulfillment_status);
        $this->assertNotNull($order->delivered_at);
        $this->assertSame(3, CustomerNotification::where('notifiable_id', $order->id)->where('type', 'order_update')->count());
    }

    public function test_pending_or_rejected_orders_cannot_get_fulfillment_updates(): void
    {
        [$shop, $owner, $connection] = $this->whatsappShop();
        $order = $this->acceptedOrder($shop, $connection);
        $order->update(['status' => WhatsAppOrderStatus::Pending]);

        $this->expectException(ValidationException::class);
        UpdateWhatsAppOrderFulfillment::handle($order, FulfillmentStatus::Packed, $owner->id);
    }

    public function test_review_request_goes_out_a_day_after_delivery_and_the_reply_flow_captures_consent(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $order = $this->acceptedOrder($shop, $connection);
        $order->update(['fulfillment_status' => FulfillmentStatus::Delivered, 'delivered_at' => now()]);

        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertNull($order->fresh()->review_requested_at);

        $this->travel(25)->hours();
        WhatsAppContact::query()->update(['last_inbound_at' => now()]);
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertNotNull($order->fresh()->review_requested_at);
        $request = collect($this->sentBodies())->last();
        $this->assertSame('rate:5', array_key_first(array_column(array_column($request['interactive']['action']['buttons'], 'reply'), 'title', 'id')));

        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertSame(1, CustomerNotification::where('type', 'review_request')->count());

        $bot = new WhatsAppChatbot($connection);
        $bot->handleInbound(['from' => $this->customer, 'id' => 'b1', 'type' => 'button', 'button' => ['payload' => 'rate:5', 'text' => 'Excellent']], 'Ali');
        $bot->handleInbound($this->textMessage($this->customer, 'Fast delivery, original charger'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'consent:yes'), 'Ali');

        $review = CustomerReview::withoutGlobalScopes()->sole();
        $this->assertSame(5, $review->rating);
        $this->assertSame('Fast delivery, original charger', $review->comment);
        $this->assertTrue($review->isPublishable());
        $this->assertSame($order->id, $review->whatsapp_order_id);
    }

    public function test_a_low_rating_is_kept_private_and_never_asked_for_consent(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->replyMessage($this->customer, 'rate:2'), null);
        $bot->handleInbound($this->textMessage($this->customer, 'Late delivery'), null);

        $review = CustomerReview::withoutGlobalScopes()->sole();
        $this->assertSame('complete', $review->status);
        $this->assertFalse($review->isPublishable());
        $this->assertStringContainsString('sorry', collect($this->sentBodies())->last()['text']['body']);
    }

    public function test_asking_for_an_out_of_stock_product_offers_notify_me_and_restock_sends_once(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Samsung 25W Charger', 2500, 0);
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'samsung charger'), null);
        $offer = collect($this->sentBodies())->last();
        $this->assertStringContainsString('out of stock', $offer['interactive']['body']['text']);

        $bot->handleInbound($this->replyMessage($this->customer, 'notify:'.$product->id), null);
        $bot->handleInbound($this->replyMessage($this->customer, 'notify:'.$product->id), null);
        $this->assertSame(1, RestockSubscription::withoutGlobalScopes()->count());

        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertNull(RestockSubscription::withoutGlobalScopes()->sole()->notified_at);

        $product->update(['stock_quantity' => 4]);
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();

        $this->assertNotNull(RestockSubscription::withoutGlobalScopes()->sole()->notified_at);
        $this->assertSame(1, CustomerNotification::where('type', 'restock_alert')->count());
        $this->assertStringContainsString('Samsung 25W Charger is back in stock', collect($this->sentBodies())->last()['text']['body']);
    }

    public function test_roman_urdu_is_detected_and_language_can_be_switched(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $this->listedProduct($shop, 'Charger');
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'bhai kya charger hai'), null);
        $this->assertSame('ur', WhatsAppContact::withoutGlobalScopes()->sole()->language);

        $bot->handleInbound($this->textMessage($this->customer, 'menu'), null);
        $this->assertStringContainsString('khush aamdeed', collect($this->sentBodies())->last()['interactive']['body']['text']);

        $bot->handleInbound($this->textMessage($this->customer, 'ENGLISH'), null);
        $bot->handleInbound($this->textMessage($this->customer, 'menu'), null);
        $this->assertSame('en', WhatsAppContact::withoutGlobalScopes()->sole()->language);
        $this->assertStringContainsString('Welcome to', collect($this->sentBodies())->last()['interactive']['body']['text']);
    }

    public function test_free_text_search_with_one_match_opens_the_product(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $this->listedProduct($shop, 'Anker Power Bank 20000mAh', 6500, 3);
        $this->listedProduct($shop, 'USB Cable', 300, 10);

        (new WhatsAppChatbot($connection))->handleInbound($this->textMessage($this->customer, 'power bank'), null);

        $last = collect($this->sentBodies())->last();
        $this->assertStringContainsString('Anker Power Bank 20000mAh', $last['interactive']['body']['text']);
        $this->assertSame('awaiting_quantity', WhatsAppContact::withoutGlobalScopes()->sole()->bot_state['step']);
    }

    public function test_repair_ready_message_includes_the_remaining_amount_and_touches_nothing_on_the_repair(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $this->approveTemplates($connection);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ahmed', 'phone' => '03009998887']);
        $repair = Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'category' => 'mobile', 'description' => 'Screen', 'amount' => 3000, 'discount' => 0, 'total' => 3000, 'payment_status' => 'partial', 'amount_paid' => 1800]);
        $before = $repair->fresh()->getAttributes();
        $this->actingAs($owner);

        Livewire::test('whatsapp-messages.index', ['tab' => 'repairs'])
            ->set('tab', 'repairs')
            ->call('notifyRepairReady', $repair->id);

        $sent = collect($this->sentBodies())->last();
        $this->assertSame('hok_repair_ready_v1', $sent['template']['name']);
        $this->assertSame('Amount remaining on this repair: Rs 1,200.', $sent['template']['components'][0]['parameters'][4]['text']);
        $this->assertSame('923009998887', $sent['to']);
        $this->assertSame($before, $repair->fresh()->getAttributes());
        $this->assertSame(1200.0, $repair->fresh()->amountOwed());
    }

    public function test_customer_messages_screen_requires_whatsapp_and_permission(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $this->actingAs($owner)->get(route('whatsapp-messages.index'))->assertOk();

        $shop->update(['whatsapp_enabled' => false]);
        $this->actingAs($owner->fresh())->get(route('whatsapp-messages.index'))->assertForbidden();
    }
}
