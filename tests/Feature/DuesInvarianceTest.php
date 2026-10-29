<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\UpdateWhatsAppOrderFulfillment;
use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Repair;
use App\Models\ShopChannelConnection;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\Marketing\PostComposer;
use App\Services\WhatsApp\CustomerNotifications;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class DuesInvarianceTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    public function test_running_every_new_customer_facing_feature_leaves_every_existing_balance_unchanged(): void
    {
        $this->fakeGraph();
        Storage::fake('public', ['url' => 'https://shop.test/storage']);

        [$shop, $owner, $bilal, $sana] = DuesBaselineTest::seedDuesFixture($this);
        DuesBaselineTest::assertGoldenDues($this, $bilal, $sana);

        $shop->update(['whatsapp_enabled' => true, 'social_enabled' => true]);
        $connection = ShopChannelConnection::create([
            'shop_id' => $shop->id, 'provider' => ChannelProvider::WhatsApp, 'external_id' => '777', 'waba_id' => '900',
            'access_token' => 'tok', 'status' => ConnectionStatus::Connected, 'connected_at' => now(),
        ]);
        $this->approveTemplates($connection);
        $notifications = app(CustomerNotifications::class);

        $this->assertTrue($notifications->paymentReminder($bilal, $owner->id)->wasSent());
        $notifications->repairReady(Repair::where('customer_id', $bilal->id)->where('payment_status', 'partial')->first(), $owner->id);

        $iphone = Product::where('name', 'iPhone 13')->first();
        $iphone->update(['whatsapp_listed' => true]);
        $bot = new WhatsAppChatbot($connection);
        $bot->handleInbound($this->textMessage('923001112223', 'bhai iphone hai?'), 'Bilal');
        $bot->handleInbound($this->replyMessage('923001112223', 'qty:'.$iphone->id.':1'), 'Bilal');
        $bot->handleInbound($this->replyMessage('923001112223', 'checkout'), 'Bilal');
        $bot->handleInbound($this->textMessage('923001112223', 'pickup'), 'Bilal');
        $bot->handleInbound($this->replyMessage('923001112223', 'confirm'), 'Bilal');

        $order = WhatsAppOrder::where('status', WhatsAppOrderStatus::Pending->value)->sole();
        $this->assertSame($bilal->id, $order->customer_id);
        DuesBaselineTest::assertGoldenDues($this, $bilal, $sana);

        $walkInContact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923331234567', 'last_inbound_at' => now()]);
        $paidOrder = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $walkInContact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now()]);
        WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $paidOrder->id, 'product_id' => $iphone->id, 'product_name' => 'iPhone 13', 'quantity' => 1, 'quoted_unit_price' => 50000]);
        AcceptWhatsAppOrder::handle($paidOrder, [['product_id' => $iphone->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => 50000.0]], 0, null, 50000, $owner->id);
        foreach ([FulfillmentStatus::Packed, FulfillmentStatus::ReadyForPickup, FulfillmentStatus::Delivered] as $status) {
            UpdateWhatsAppOrderFulfillment::handle($paidOrder->fresh(), $status, $owner->id);
        }

        $this->travel(25)->hours();
        WhatsAppContact::query()->update(['last_inbound_at' => now()]);
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $bot->handleInbound($this->replyMessage('923331234567', 'rate:5'), null);
        $bot->handleInbound($this->textMessage('923331234567', 'skip'), null);
        $bot->handleInbound($this->replyMessage('923331234567', 'consent:yes'), null);

        $composer = app(PostComposer::class);
        $composer->createProductPost($shop, $owner->id, $iphone->fresh());
        $composer->createReviewPost($shop, $owner->id, \App\Models\CustomerReview::sole());
        $composer->createQrCode($shop, $owner->id, 'Counter');

        DuesBaselineTest::assertGoldenDues($this, $bilal, $sana);
        $this->assertSame(1, Customer::where('id', $bilal->id)->first()->udhaarTransactions()->where('type', 'given')->count());
    }

    public function test_the_full_meta_ads_flow_leaves_every_existing_balance_unchanged(): void
    {
        Storage::fake('public', ['url' => 'https://shop.test/storage']);
        Http::fake([
            'graph.facebook.com/*/act_1/campaigns' => Http::response(['id' => 'cmp-1'], 200),
            'graph.facebook.com/*/act_1/adsets' => Http::response(['id' => 'set-1'], 200),
            'graph.facebook.com/*/act_1/adimages' => Http::response(['images' => ['a.jpg' => ['hash' => 'h']]], 200),
            'graph.facebook.com/*/act_1/adcreatives' => Http::response(['id' => 'cr-1'], 200),
            'graph.facebook.com/*/act_1/ads' => Http::response(['id' => 'ad-1'], 200),
            'graph.facebook.com/*/cmp-1/insights*' => Http::response(['data' => [['spend' => '900']]], 200),
            'graph.facebook.com/*' => Http::response(['success' => true, 'effective_status' => 'ACTIVE', 'messages' => [['id' => 'w']]], 200),
        ]);

        [$shop, $owner, $bilal, $sana] = DuesBaselineTest::seedDuesFixture($this);
        $shop->update(['whatsapp_enabled' => true, 'social_enabled' => true, 'ads_enabled' => true]);
        ShopChannelConnection::create(['shop_id' => $shop->id, 'provider' => ChannelProvider::WhatsApp, 'external_id' => '777', 'meta' => ['display_phone_number' => '+92 300 0000000'], 'access_token' => 't', 'status' => ConnectionStatus::Connected, 'connected_at' => now()]);
        $this->metaConnections($shop);
        ShopChannelConnection::create(['shop_id' => $shop->id, 'provider' => ChannelProvider::MetaAds, 'external_id' => 'act_1', 'access_token' => 't', 'meta' => ['currency' => 'PKR'], 'status' => ConnectionStatus::Connected, 'connected_at' => now()]);

        $iphone = Product::where('name', 'iPhone 13')->first();
        $post = app(PostComposer::class)->createProductPost($shop->fresh(), $owner->id, $iphone);
        $ads = app(\App\Services\Marketing\MetaAdsManager::class);
        $campaign = $ads->createDraft($shop->fresh(), $owner->id, $post, \App\Enums\AdGoal::WhatsAppMessages, 1000, now()->addMinutes(10), now()->addDays(3), ['mode' => 'country', 'countries' => ['PK'], 'whatsapp_status' => true]);
        $campaign = $ads->build($campaign);
        $this->assertTrue($campaign->targeting['whatsapp_status']);
        $campaign = $ads->launch($campaign, $owner->id, $campaign->maxSpend());
        $ads->sync($campaign);

        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923331234567', 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'referral' => ['source_id' => 'ad-1']]);
        WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $iphone->id, 'product_name' => 'iPhone 13', 'quantity' => 1, 'quoted_unit_price' => 50000]);
        AcceptWhatsAppOrder::handle($order, [['product_id' => $iphone->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => 50000.0]], 0, null, 50000, $owner->id);
        $ads->setRunning($campaign->fresh(), false);
        $ads->end($campaign->fresh());

        $this->assertSame(1, \App\Services\Marketing\MarketingStats::adResults($campaign->fresh())['orders']);
        DuesBaselineTest::assertGoldenDues($this, $bilal, $sana);
    }
}
