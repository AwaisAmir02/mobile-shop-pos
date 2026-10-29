<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\CancelSale;
use App\Enums\ChannelProvider;
use App\Enums\IntegrationProvider;
use App\Enums\PostStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\CustomerReview;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\PlatformIntegration;
use App\Models\Role;
use App\Models\Sale;
use App\Models\ShopChannelConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\Marketing\MarketingStats;
use App\Services\Marketing\MetaOnboarding;
use App\Services\Marketing\PostComposer;
use App\Services\WhatsApp\WhatsAppChatbot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class SocialMarketingTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public', ['url' => 'https://shop.test/storage']);
    }

    protected function socialShop(): array
    {
        [$shop, $owner, $whatsapp] = $this->whatsappShop();
        $shop->update(['social_enabled' => true]);
        $whatsapp->update(['meta' => ['display_phone_number' => '+92 300 7654321']]);
        [$facebook, $instagram] = $this->metaConnections($shop);
        $this->actingAs($owner->fresh());

        return [$shop->fresh(), $owner->fresh(), $whatsapp, $facebook, $instagram];
    }

    protected function fakeMeta(): void
    {
        Http::fake([
            'graph.facebook.com/*/photos' => Http::response(['id' => 'photo-1', 'post_id' => 'page_post-1'], 200),
            'graph.facebook.com/*/photo_stories' => Http::response(['success' => true, 'post_id' => 'story-1'], 200),
            'graph.facebook.com/*/media_publish' => Http::response(['id' => 'ig-media-1'], 200),
            'graph.facebook.com/*/media' => Http::response(['id' => 'container-1'], 200),
            'graph.facebook.com/*/container-1*' => Http::response(['status_code' => 'FINISHED'], 200),
            'graph.facebook.com/*/ig-media-1/insights*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 321]]], ['name' => 'likes', 'values' => [['value' => 12]]]]], 200),
            'graph.facebook.com/*/ig-media-1*' => Http::response(['permalink' => 'https://instagram.com/p/abc'], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ]);
    }

    public function test_meta_login_lists_pages_and_attaching_connects_page_and_instagram(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $shop->update(['social_enabled' => true]);
        PlatformIntegration::create(['provider' => IntegrationProvider::Meta, 'app_id' => '42', 'app_secret' => 's', 'config' => ['login_config_id' => '77'], 'enabled' => true]);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'user-token'], 200),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => 'P1', 'name' => 'Ali Mobiles', 'access_token' => 'page-tok-1', 'tasks' => ['CREATE_CONTENT'], 'instagram_business_account' => ['id' => 'IG1', 'username' => 'alimobiles']],
                ['id' => 'P2', 'name' => 'Personal Fan Page', 'access_token' => 'page-tok-2', 'tasks' => ['CREATE_CONTENT']],
                ['id' => 'P3', 'name' => 'Read only', 'access_token' => 'page-tok-3', 'tasks' => ['ANALYZE']],
            ]], 200),
        ]);
        $this->actingAs($owner->fresh());

        Livewire::test('settings.integrations')
            ->call('completeMetaLogin', 'auth-code')
            ->assertSee('Ali Mobiles')
            ->assertSee('Personal Fan Page')
            ->assertDontSee('Read only')
            ->assertDontSee('page-tok-1')
            ->call('attachMetaPage', 'P1');

        $facebook = ShopChannelConnection::activeFor($shop->id, ChannelProvider::Facebook);
        $instagram = ShopChannelConnection::activeFor($shop->id, ChannelProvider::Instagram);
        $this->assertSame('P1', $facebook->external_id);
        $this->assertSame('page-tok-1', $facebook->access_token);
        $this->assertSame('IG1', $instagram->external_id);
        $this->assertSame('@alimobiles', $instagram->display_name);
        $this->assertSame([], app(MetaOnboarding::class)->candidates($owner->id));
    }

    public function test_product_post_gets_cards_ref_code_and_whatsapp_order_link(): void
    {
        [$shop, $owner] = $this->socialShop();
        $product = $this->listedProduct($shop, 'Anker Power Bank', 6500, 2);

        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $product);

        $this->assertMatchesRegularExpression('/^[A-Z0-9]{6}$/', $post->ref_code);
        Storage::disk('public')->assertExists($post->image_path);
        Storage::disk('public')->assertExists($post->story_image_path);
        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($post->story_image_path));
        $this->assertSame([1080, 1920], [$w, $h]);
        $this->assertStringContainsString('Rs 6,500', $post->caption);
        $this->assertStringContainsString('Only 2 left', $post->caption);
        $this->assertStringContainsString('https://wa.me/923007654321?text=', $post->caption);
        $this->assertStringContainsString(rawurlencode($post->refTag()), $post->caption);
    }

    public function test_publish_now_posts_to_each_chosen_surface_and_records_results(): void
    {
        $this->fakeMeta();
        [$shop, $owner] = $this->socialShop();
        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $this->listedProduct($shop));

        Livewire::test('marketing.post', ['post' => $post])
            ->set('surfaces', ['fb_feed', 'fb_story', 'ig_feed', 'ig_story'])
            ->call('publish')
            ->assertHasNoErrors();

        $post->refresh();
        $this->assertSame(PostStatus::Published, $post->status);
        $targets = $post->targets()->get()->keyBy(fn ($t) => $t->surface->value);
        $this->assertSame('page_post-1', $targets['fb_feed']->external_id);
        $this->assertSame('story-1', $targets['fb_story']->external_id);
        $this->assertSame('ig-media-1', $targets['ig_feed']->external_id);
        $this->assertSame('https://instagram.com/p/abc', $targets['ig_feed']->permalink);

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/photos') && $r['published'] === 'false');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/media') && ($r['media_type'] ?? null) === 'STORIES' && ! isset($r['caption']));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/media') && str_contains($r['caption'] ?? '', $post->refTag()));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/photos') && $r->hasHeader('Authorization', 'Bearer page-token-'.$shop->id));

        $this->artisan('marketing:sync-insights')->assertSuccessful();
        $this->assertSame(321, $targets['ig_feed']->fresh()->metric('reach'));
    }

    public function test_scheduled_post_waits_until_due(): void
    {
        $this->fakeMeta();
        [$shop, $owner] = $this->socialShop();
        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $this->listedProduct($shop));

        Livewire::test('marketing.post', ['post' => $post])
            ->set('surfaces', ['fb_feed'])
            ->set('scheduleAt', now()->addHours(3)->format('Y-m-d\TH:i'))
            ->call('publish')
            ->assertHasNoErrors();

        $this->assertSame(PostStatus::Scheduled, $post->fresh()->status);
        $this->artisan('marketing:publish-due')->assertSuccessful();
        $this->assertSame('pending', MarketingPostTarget::sole()->status);

        $this->travel(4)->hours();
        $this->artisan('marketing:publish-due')->assertSuccessful();
        $this->assertSame('published', MarketingPostTarget::sole()->status);
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_a_failed_placement_is_recorded_and_can_be_retried(): void
    {
        [$shop, $owner] = $this->socialShop();
        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $this->listedProduct($shop));
        $failing = true;
        Http::fake(function () use (&$failing) {
            return $failing
                ? Http::response(['error' => ['message' => '(#200) Permissions error']], 403)
                : Http::response(['id' => 'photo-1', 'post_id' => 'page_post-1'], 200);
        });

        app(PostComposer::class)->schedule($post, ['fb_feed'], null, 'Caption');
        $this->assertSame(PostStatus::Failed, $post->fresh()->status);
        $this->assertSame('(#200) Permissions error', MarketingPostTarget::sole()->error);

        $failing = false;
        Livewire::test('marketing.post', ['post' => $post->fresh()])->call('retryFailed');
        $this->assertSame(PostStatus::Published, $post->fresh()->status);
    }

    public function test_publishing_refuses_non_https_images(): void
    {
        Storage::fake('public', ['url' => 'http://localhost/storage']);
        $this->fakeMeta();
        [$shop, $owner] = $this->socialShop();
        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $this->listedProduct($shop));

        app(PostComposer::class)->schedule($post, ['ig_feed'], null, 'Caption');

        $this->assertStringContainsString('public HTTPS', MarketingPostTarget::sole()->error);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/media'));
    }

    public function test_surfaces_that_are_not_connected_are_rejected(): void
    {
        [$shop, $owner] = $this->whatsappShop();
        $shop->update(['social_enabled' => true]);
        $this->metaConnections($shop, instagram: false);
        $this->actingAs($owner->fresh());
        $post = app(PostComposer::class)->createProductPost($shop->fresh(), $owner->id, $this->listedProduct($shop));

        Livewire::test('marketing.post', ['post' => $post])
            ->set('surfaces', ['ig_feed'])
            ->call('publish')
            ->assertHasErrors(['surfaces']);

        $this->assertSame(0, MarketingPostTarget::count());
    }

    public function test_only_consented_reviews_become_posts(): void
    {
        [$shop, $owner] = $this->socialShop();
        $private = CustomerReview::create(['shop_id' => $shop->id, 'rating' => 5, 'comment' => 'Great', 'display_name' => 'Sana Ahmed', 'status' => 'complete', 'publish_consent' => false]);
        $shared = CustomerReview::create(['shop_id' => $shop->id, 'rating' => 5, 'comment' => 'Great service', 'display_name' => 'Sana Ahmed', 'status' => 'complete', 'publish_consent' => true, 'consent_at' => now()]);

        Livewire::test('marketing.reviews')->call('createPost', $private->id);
        $this->assertSame(0, MarketingPost::count());

        Livewire::test('marketing.reviews')->call('createPost', $shared->id)->assertRedirect();
        $post = MarketingPost::sole();
        $this->assertSame($shared->id, $post->customer_review_id);
        $this->assertStringContainsString('Sana A.', $post->caption);
        $this->assertStringNotContainsString('Ahmed', $post->caption);
    }

    public function test_qr_code_opens_catalog_and_its_orders_are_attributed(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $whatsapp] = $this->socialShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);

        Livewire::test('marketing.qr')->set('title', 'Counter')->set('mode', 'catalog')->call('create')->assertHasNoErrors();
        $qr = MarketingPost::sole();
        $this->assertSame('CATALOG '.$qr->refTag(), $qr->caption);

        $png = $this->get(route('marketing.qr.download', ['post' => $qr, 'format' => 'png']));
        $png->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('marketing.qr.download', ['post' => $qr, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $bot = new WhatsAppChatbot($whatsapp);
        $bot->handleInbound($this->textMessage('923001234567', $qr->caption), null);
        $this->assertSame('list', collect($this->sentBodies())->last()['interactive']['type']);
        $bot->handleInbound($this->replyMessage('923001234567', 'qty:'.$product->id.':1'), null);
        $bot->handleInbound($this->replyMessage('923001234567', 'checkout'), null);
        $bot->handleInbound($this->textMessage('923001234567', 'pickup'), null);
        $bot->handleInbound($this->replyMessage('923001234567', 'confirm'), null);

        $order = WhatsAppOrder::where('status', WhatsAppOrderStatus::Pending->value)->sole();
        $this->assertSame($qr->ref_code, $order->origin_ref);

        AcceptWhatsAppOrder::handle($order, [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => 1500.0]], 0, null, 1500, $owner->id);

        $stats = new MarketingStats(now()->subDay(), now());
        $this->assertSame(['orders' => 1, 'revenue' => 1500.0], $stats->attribution()['qr']);
        $this->assertSame(1500.0, $stats->salesBySource()['whatsapp']['revenue']);
    }

    public function test_cancelled_sales_drop_out_of_marketing_numbers(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $whatsapp] = $this->socialShop();
        $product = $this->listedProduct($shop, 'Charger', 1500, 5);
        $post = app(PostComposer::class)->createProductPost($shop, $owner->id, $product);
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001234567', 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'origin_ref' => $post->ref_code]);
        WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => 'Charger', 'quantity' => 2, 'quoted_unit_price' => 1500]);
        $sale = AcceptWhatsAppOrder::handle($order, [['product_id' => $product->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 1500.0]], 0, null, 3000, $owner->id);

        $stats = fn () => new MarketingStats(now()->subDay(), now());
        $this->assertSame(['orders' => 1, 'revenue' => 3000.0], $stats()->attribution()['posts']);
        $this->assertSame(1, $stats()->topPosts()->firstWhere('post.id', $post->id)['orders']);

        CancelSale::handle(Sale::find($sale->id), $owner->id, 'Returned');

        $this->assertSame(['orders' => 0, 'revenue' => 0.0], $stats()->attribution()['posts']);
        $this->assertSame(0.0, $stats()->salesBySource()['whatsapp']['revenue']);
    }

    public function test_marketing_screens_require_an_enabled_channel_and_permission(): void
    {
        [$shop, $owner] = $this->socialShop();
        $this->get(route('marketing.index'))->assertOk();
        $this->get(route('marketing.posts'))->assertOk();
        $this->get(route('marketing.qr'))->assertOk();
        $this->get(route('marketing.reviews'))->assertOk();

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Poster', 'permissions' => ['marketing']]);
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]));
        $this->get(route('marketing.posts'))->assertOk();
        $this->get(route('marketing.index'))->assertForbidden();

        $shop->update(['whatsapp_enabled' => false, 'social_enabled' => false]);
        $this->actingAs($owner->fresh())->get(route('marketing.posts'))->assertForbidden();
    }

    public function test_another_shops_post_is_not_reachable(): void
    {
        [$shopA, $ownerA] = $this->socialShop();
        [$shopB, $ownerB] = $this->whatsappShop('Shop B', '999');
        $postB = app(PostComposer::class)->createProductPost($shopB, $ownerB->id, $this->listedProduct($shopB));

        $this->actingAs($ownerA)->get(route('marketing.posts.show', $postB->id))->assertNotFound();
    }
}
