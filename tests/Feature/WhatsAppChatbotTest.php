<?php

namespace Tests\Feature;

use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppChatbotTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    public function test_full_conversation_produces_a_pending_order_with_quoted_items(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'Hi'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'browse'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'product:'.$product->id, 'list_reply'), 'Ali');
        $bot->handleInbound($this->textMessage($this->customer, '3'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'checkout'), 'Ali');
        $bot->handleInbound($this->textMessage($this->customer, 'House 5, Street 2, Lahore'), 'Ali');
        $bot->handleInbound($this->replyMessage($this->customer, 'confirm'), 'Ali');

        $order = WhatsAppOrder::withoutGlobalScopes()->sole();

        $this->assertSame(WhatsAppOrderStatus::Pending, $order->status);
        $this->assertSame($shop->id, $order->shop_id);
        $this->assertSame(4500.0, (float) $order->quoted_total);
        $this->assertSame('House 5, Street 2, Lahore', $order->delivery_address);
        $this->assertNotNull($order->submitted_at);
        $this->assertSame(3, $order->items()->withoutGlobalScopes()->sole()->quantity);
        $this->assertSame(5, $product->fresh()->stock_quantity);

        $contact = WhatsAppContact::withoutGlobalScopes()->sole();
        $this->assertSame('Ali', $contact->profile_name);
        $this->assertSame('idle', $contact->bot_state['step']);

        $list = collect($this->sentBodies())->firstWhere('interactive.type', 'list');
        $this->assertSame('product:'.$product->id, $list['interactive']['action']['sections'][0]['rows'][0]['id']);
    }

    public function test_catalog_only_offers_listed_in_stock_products_of_this_shop(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        [$otherShop] = $this->whatsappShop('Shop B', '999');
        $listed = $this->listedProduct($shop, 'Listed');
        $this->listedProduct($shop, 'Hidden', listed: false);
        $this->listedProduct($shop, 'Sold Out', stock: 0);
        $this->listedProduct($otherShop, 'Other Shop Item');

        (new WhatsAppChatbot($connection))->handleInbound($this->replyMessage($this->customer, 'browse'), null);

        $rows = collect($this->sentBodies())->firstWhere('interactive.type', 'list')['interactive']['action']['sections'][0]['rows'];
        $this->assertSame(['product:'.$listed->id], array_column($rows, 'id'));
    }

    public function test_cannot_add_more_than_stock_or_an_unlisted_or_foreign_product(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        [$otherShop] = $this->whatsappShop('Shop B', '999');
        $product = $this->listedProduct($shop, 'Charger', stock: 2);
        $unlisted = $this->listedProduct($shop, 'Hidden', listed: false);
        $foreign = $this->listedProduct($otherShop, 'Foreign');
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$product->id}:2"), null);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$product->id}:1"), null);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$unlisted->id}:1"), null);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$foreign->id}:1"), null);

        $order = WhatsAppOrder::withoutGlobalScopes()->sole();
        $items = $order->items()->withoutGlobalScopes()->get();

        $this->assertCount(1, $items);
        $this->assertSame(2, $items->first()->quantity);
        $this->assertSame($product->id, $items->first()->product_id);
    }

    public function test_contact_is_matched_to_an_existing_customer_by_normalized_phone(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Ali Khan', 'phone' => '0300-1234567']);

        (new WhatsAppChatbot($connection))->handleInbound($this->textMessage($this->customer, 'hi'), 'Ali');

        $this->assertSame($customer->id, WhatsAppContact::withoutGlobalScopes()->sole()->customer_id);
    }

    public function test_help_hands_off_to_the_shop_and_menu_resumes_the_bot(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'help'), null);
        $sentAfterHandoff = count($this->sentBodies());

        $bot->handleInbound($this->textMessage($this->customer, 'do you have iphone 15?'), null);
        $this->assertCount($sentAfterHandoff, $this->sentBodies());

        $bot->handleInbound($this->textMessage($this->customer, 'menu'), null);
        $this->assertCount($sentAfterHandoff + 1, $this->sentBodies());
    }

    public function test_a_business_reply_from_the_app_pauses_the_bot_for_that_contact(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->textMessage($this->customer, 'hi'), null);
        $bot->handleBusinessEcho($this->customer);
        $before = count($this->sentBodies());

        $bot->handleInbound($this->textMessage($this->customer, 'thanks'), null);

        $this->assertCount($before, $this->sentBodies());
    }

    public function test_ref_code_and_ad_referral_are_carried_onto_the_order(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $product = $this->listedProduct($shop);
        $bot = new WhatsAppChatbot($connection);

        $first = $this->textMessage($this->customer, 'I want this REF-AB12CD');
        $first['referral'] = ['source_type' => 'ad', 'source_id' => 'ad-42'];
        $bot->handleInbound($first, null);
        $bot->handleInbound($this->replyMessage($this->customer, "qty:{$product->id}:1"), null);
        $bot->handleInbound($this->replyMessage($this->customer, 'checkout'), null);
        $bot->handleInbound($this->textMessage($this->customer, 'pickup'), null);
        $bot->handleInbound($this->replyMessage($this->customer, 'confirm'), null);

        $order = WhatsAppOrder::withoutGlobalScopes()->sole();
        $this->assertSame('AB12CD', $order->origin_ref);
        $this->assertSame('ad-42', $order->referral['source_id']);
        $this->assertSame('Pickup from shop', $order->delivery_address);
        $this->assertArrayNotHasKey('origin_ref', WhatsAppContact::withoutGlobalScopes()->sole()->bot_state);
    }

    public function test_stop_opts_out_of_marketing(): void
    {
        $this->fakeGraph();
        [, , $connection] = $this->whatsappShop();
        $bot = new WhatsAppChatbot($connection);

        $bot->handleInbound($this->replyMessage($this->customer, 'optin'), null);
        $this->assertTrue(WhatsAppContact::withoutGlobalScopes()->sole()->isMarketingOptedIn());

        $this->travel(1)->minutes();
        $bot->handleInbound($this->textMessage($this->customer, 'STOP'), null);
        $this->assertFalse(WhatsAppContact::withoutGlobalScopes()->sole()->isMarketingOptedIn());
    }
}
