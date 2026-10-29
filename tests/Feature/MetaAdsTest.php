<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\CancelSale;
use App\Enums\AdCampaignStatus;
use App\Enums\AdGoal;
use App\Enums\ChannelProvider;
use App\Enums\ConnectionStatus;
use App\Enums\IntegrationProvider;
use App\Enums\WhatsAppOrderStatus;
use App\Models\MarketingPost;
use App\Models\MarketingPostTarget;
use App\Models\MetaAdCampaign;
use App\Models\PlatformIntegration;
use App\Models\Role;
use App\Models\Sale;
use App\Models\ShopChannelConnection;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Services\Marketing\MarketingStats;
use App\Services\Marketing\MetaAdsManager;
use App\Services\Marketing\PostComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class MetaAdsTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public', ['url' => 'https://shop.test/storage']);
    }

    protected function adsShop(bool $withAdAccount = true): array
    {
        [$shop, $owner, $whatsapp] = $this->whatsappShop();
        $shop->update(['social_enabled' => true, 'ads_enabled' => true]);
        $whatsapp->update(['meta' => ['display_phone_number' => '+92 300 7654321']]);
        [$facebook] = $this->metaConnections($shop);

        $adAccount = $withAdAccount ? ShopChannelConnection::create([
            'shop_id' => $shop->id,
            'provider' => ChannelProvider::MetaAds,
            'external_id' => 'act_1',
            'display_name' => 'Ali Mobiles Ads',
            'access_token' => 'user-ads-token',
            'token_expires_at' => now()->addDays(60),
            'meta' => ['currency' => 'PKR', 'min_daily_budget' => 25000],
            'status' => ConnectionStatus::Connected,
            'connected_at' => now(),
        ]) : null;

        $this->actingAs($owner->fresh());
        $post = app(PostComposer::class)->createProductPost($shop->fresh(), $owner->id, $this->listedProduct($shop, 'Anker Power Bank', 6500, 5));

        return [$shop->fresh(), $owner->fresh(), $post, $adAccount, $facebook, $whatsapp];
    }

    protected function fakeAds(array $overrides = []): void
    {
        Http::fake(array_merge([
            'graph.facebook.com/*/act_1/campaigns' => Http::response(['id' => 'cmp-1'], 200),
            'graph.facebook.com/*/act_1/adsets' => Http::response(['id' => 'set-1'], 200),
            'graph.facebook.com/*/act_1/adimages' => Http::response(['images' => ['card.jpg' => ['hash' => 'hash-1']]], 200),
            'graph.facebook.com/*/act_1/adcreatives' => Http::response(['id' => 'cr-1'], 200),
            'graph.facebook.com/*/act_1/ads' => Http::response(['id' => 'ad-1'], 200),
            'graph.facebook.com/*/cmp-1/insights*' => Http::response(['data' => [[
                'spend' => '2400.50', 'impressions' => '18000', 'reach' => '9100', 'clicks' => '310',
                'actions' => [['action_type' => MetaAdsManager::CONVERSATION_ACTION, 'value' => '42'], ['action_type' => 'post_engagement', 'value' => '500']],
            ]]], 200),
            'graph.facebook.com/*/ad-1*' => Http::response(['success' => true, 'effective_status' => 'ACTIVE'], 200),
            'graph.facebook.com/*/set-1*' => Http::response(['success' => true], 200),
            'graph.facebook.com/*/cmp-1*' => Http::response(['success' => true, 'effective_status' => 'ACTIVE'], 200),
            'graph.facebook.com/*/search*' => Http::response(['data' => [['key' => '2211096', 'name' => 'Lahore', 'region' => 'Punjab']]], 200),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.out']]], 200),
        ], $overrides));
    }

    protected function createViaForm(MarketingPost $post, array $set = [])
    {
        $component = Livewire::test('marketing.ad-create', ['post' => $post->id])
            ->set('citySearch', 'Laho')
            ->call('addCity', '2211096');

        foreach (array_merge(['dailyBudget' => '1000', 'days' => '5', 'gender' => 'female', 'ageMin' => '20', 'ageMax' => '45'], $set) as $key => $value) {
            $component->set($key, $value);
        }

        return $component->call('create');
    }

    public function test_ad_account_onboarding_keeps_only_active_accounts_and_hides_the_token(): void
    {
        [$shop, $owner] = $this->adsShop(withAdAccount: false);
        PlatformIntegration::create(['provider' => IntegrationProvider::Meta, 'app_id' => '42', 'app_secret' => 's', 'config' => ['login_config_id' => '7', 'ads_login_config_id' => '8'], 'enabled' => true]);

        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'long-user-token', 'expires_in' => 5184000], 200),
            'graph.facebook.com/*/me/adaccounts*' => Http::response(['data' => [
                ['id' => 'act_9', 'name' => 'Ali Mobiles Ads', 'currency' => 'PKR', 'account_status' => 1, 'min_daily_budget' => 26500],
                ['id' => 'act_10', 'name' => 'Disabled account', 'currency' => 'PKR', 'account_status' => 2],
            ]], 200),
        ]);

        Livewire::test('settings.integrations')
            ->call('completeAdsLogin', 'code-1')
            ->assertHasNoErrors();

        $connection = MetaAdsManager::adAccount($shop->id);
        $this->assertSame('act_9', $connection->external_id);
        $this->assertSame('long-user-token', $connection->access_token);
        $this->assertTrue($connection->token_expires_at->between(now()->addDays(59), now()->addDays(61)));
        $this->assertSame(265.0, MetaAdsManager::minDailyBudget($connection));
    }

    public function test_setup_creates_everything_paused_with_the_right_budget_targeting_and_whatsapp_destination(): void
    {
        $this->fakeAds();
        [$shop, $owner, $post, $adAccount, $facebook] = $this->adsShop();

        $this->createViaForm($post)->assertHasNoErrors()->assertRedirect();

        $campaign = MetaAdCampaign::sole();
        $this->assertSame(AdCampaignStatus::Ready, $campaign->status);
        $this->assertSame(['cmp-1', 'set-1', 'cr-1', 'ad-1'], [$campaign->meta_campaign_id, $campaign->meta_adset_id, $campaign->meta_creative_id, $campaign->meta_ad_id]);
        $this->assertSame(5000.0, $campaign->maxSpend());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/campaigns') && $r['status'] === 'PAUSED' && $r['special_ad_categories'] === [] && $r->hasHeader('Authorization', 'Bearer user-ads-token'));
        Http::assertSent(function ($r) use ($facebook) {
            if (! str_ends_with($r->url(), '/act_1/adsets')) {
                return false;
            }

            return $r['daily_budget'] === 100000
                && $r['status'] === 'PAUSED'
                && $r['destination_type'] === 'WHATSAPP'
                && $r['optimization_goal'] === 'CONVERSATIONS'
                && $r['promoted_object'] === ['page_id' => $facebook->external_id, 'whatsapp_phone_number' => '923007654321']
                && $r['targeting']['geo_locations'] === ['cities' => [['key' => '2211096', 'radius' => 15, 'distance_unit' => 'kilometer']]]
                && $r['targeting']['genders'] === [2]
                && $r['targeting']['age_min'] === 20
                && $r['targeting']['age_max'] === 45
                && $r['targeting']['publisher_platforms'] === ['facebook', 'instagram']
                && ! array_key_exists('whatsapp_positions', $r['targeting'])
                && ! array_key_exists('instagram_positions', $r['targeting']);
        });
        $this->assertFalse($campaign->targeting['whatsapp_status']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/adcreatives')
            && $r['object_story_spec']['link_data']['call_to_action']['type'] === 'WHATSAPP_MESSAGE'
            && $r['object_story_spec']['link_data']['image_hash'] === 'hash-1'
            && $r['object_story_spec']['instagram_user_id'] === 'ig-'.$shop->id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/ads') && $r['status'] === 'PAUSED' && $r['creative'] === ['creative_id' => 'cr-1']);
        Http::assertNotSent(fn ($r) => ($r->data()['status'] ?? null) === 'ACTIVE');
    }

    public function test_whatsapp_status_adds_the_status_placement_and_keeps_every_existing_placement(): void
    {
        $this->fakeAds();
        [, , $post] = $this->adsShop();

        Livewire::test('marketing.ad-create', ['post' => $post->id])->assertSee('Also show in WhatsApp Status');
        $this->createViaForm($post, ['whatsappStatus' => true])->assertHasNoErrors()->assertRedirect();

        $campaign = MetaAdCampaign::sole();
        $this->assertTrue($campaign->targeting['whatsapp_status']);
        $this->assertSame(AdCampaignStatus::Ready, $campaign->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/adsets')
            && $r['status'] === 'PAUSED'
            && $r['destination_type'] === 'WHATSAPP'
            && $r['targeting']['publisher_platforms'] === ['facebook', 'instagram', 'whatsapp']
            && $r['targeting']['whatsapp_positions'] === ['status']
            && ! array_key_exists('instagram_positions', $r['targeting'])
            && ! array_key_exists('facebook_positions', $r['targeting']));
        Http::assertNotSent(fn ($r) => ($r->data()['status'] ?? null) === 'ACTIVE');

        $this->get(route('marketing.ads.show', $campaign))->assertOk()->assertSee('WhatsApp Status');
    }

    public function test_whatsapp_status_is_hidden_and_ignored_without_instagram_or_for_post_boosts(): void
    {
        $this->fakeAds();
        [$shop, , $post, , $facebook] = $this->adsShop();

        Livewire::test('marketing.ad-create', ['post' => $post->id])
            ->set('goal', 'post_engagement')
            ->assertDontSee('Also show in WhatsApp Status');

        MarketingPostTarget::create(['shop_id' => $shop->id, 'marketing_post_id' => $post->id, 'shop_channel_connection_id' => $facebook->id, 'surface' => 'fb_feed', 'status' => 'published', 'external_id' => 'page-1_post-9']);
        $this->createViaForm($post, ['goal' => 'post_engagement', 'whatsappStatus' => true])->assertHasNoErrors();
        $this->assertFalse(MetaAdCampaign::sole()->targeting['whatsapp_status']);

        ShopChannelConnection::where('provider', ChannelProvider::Instagram->value)->delete();

        Livewire::test('marketing.ad-create', ['post' => $post->id])->assertDontSee('Also show in WhatsApp Status');
        $this->createViaForm($post, ['whatsappStatus' => true])->assertHasNoErrors();
        $this->assertFalse(MetaAdCampaign::latest('id')->first()->targeting['whatsapp_status']);

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/act_1/adsets') && in_array('whatsapp', $r['targeting']['publisher_platforms'] ?? [], true));
    }

    public function test_budget_duration_and_location_rules_are_enforced(): void
    {
        $this->fakeAds();
        [, , $post] = $this->adsShop();

        $this->createViaForm($post, ['dailyBudget' => '100'])->assertHasErrors(['dailyBudget']);
        $this->createViaForm($post, ['dailyBudget' => '60000'])->assertHasErrors(['dailyBudget']);
        $this->createViaForm($post, ['days' => '31'])->assertHasErrors(['days']);
        $this->createViaForm($post, ['ageMin' => '40', 'ageMax' => '30'])->assertHasErrors(['ageMax']);
        Livewire::test('marketing.ad-create', ['post' => $post->id])->set('locationMode', 'cities')->call('create')->assertHasErrors(['cities']);
        Livewire::test('marketing.ad-create', ['post' => $post->id])->set('locationMode', 'radius')->call('create')->assertHasErrors(['latitude', 'longitude']);

        $this->assertSame(0, MetaAdCampaign::count());
    }

    public function test_boosting_a_post_requires_it_to_be_on_facebook_and_uses_the_existing_post(): void
    {
        $this->fakeAds();
        [$shop, , $post, , $facebook] = $this->adsShop();

        $this->createViaForm($post, ['goal' => 'post_engagement'])->assertHasErrors(['goal']);

        MarketingPostTarget::create(['shop_id' => $shop->id, 'marketing_post_id' => $post->id, 'shop_channel_connection_id' => $facebook->id, 'surface' => 'fb_feed', 'status' => 'published', 'external_id' => 'page-1_post-9']);
        $this->createViaForm($post, ['goal' => 'post_engagement'])->assertHasNoErrors();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/adcreatives') && $r['object_story_id'] === 'page-1_post-9');
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/act_1/adsets') && $r['destination_type'] === 'ON_POST' && $r['optimization_goal'] === 'POST_ENGAGEMENT');
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/adimages'));
    }

    public function test_a_failed_step_keeps_what_was_created_and_retry_resumes_without_duplicates(): void
    {
        [, , $post] = $this->adsShop();
        $failAdsets = true;
        $this->fakeAds(['graph.facebook.com/*/act_1/adsets' => function () use (&$failAdsets) {
            return $failAdsets
                ? Http::response(['error' => ['message' => 'Invalid parameter', 'error_user_msg' => 'Your Page is not linked to a WhatsApp number.']], 400)
                : Http::response(['id' => 'set-1'], 200);
        }]);

        $this->createViaForm($post);
        $campaign = MetaAdCampaign::sole();
        $this->assertSame(AdCampaignStatus::Failed, $campaign->status);
        $this->assertSame('cmp-1', $campaign->meta_campaign_id);
        $this->assertSame('Your Page is not linked to a WhatsApp number.', $campaign->error);

        $failAdsets = false;
        Livewire::test('marketing.ad', ['campaign' => $campaign])->call('retrySetup');

        $this->assertSame(AdCampaignStatus::Ready, $campaign->fresh()->status);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/act_1/campaigns')));
    }

    public function test_launch_needs_spend_confirmation_and_then_activates_every_object(): void
    {
        $this->fakeAds();
        [, $owner, $post] = $this->adsShop();
        $this->createViaForm($post);
        $campaign = MetaAdCampaign::sole();

        Livewire::test('marketing.ad', ['campaign' => $campaign])
            ->assertSee('PKR 5,000.00')
            ->call('launch')
            ->assertHasErrors(['confirmSpend']);
        $this->assertSame(AdCampaignStatus::Ready, $campaign->fresh()->status);
        Http::assertNotSent(fn ($r) => ($r->data()['status'] ?? null) === 'ACTIVE');

        Livewire::test('marketing.ad', ['campaign' => $campaign])
            ->set('confirmSpend', true)
            ->call('launch')
            ->assertHasNoErrors();

        $campaign->refresh();
        $this->assertSame(AdCampaignStatus::Active, $campaign->status);
        $this->assertSame(5000.0, (float) $campaign->spend_cap_confirmed);
        $this->assertSame($owner->id, $campaign->launched_by);
        foreach (['ad-1', 'set-1', 'cmp-1'] as $id) {
            Http::assertSent(fn ($r) => str_ends_with($r->url(), '/'.$id) && ($r->data()['status'] ?? null) === 'ACTIVE');
        }

        Livewire::test('marketing.ad', ['campaign' => $campaign])->call('pause');
        $this->assertSame(AdCampaignStatus::Paused, $campaign->fresh()->status);
        Livewire::test('marketing.ad', ['campaign' => $campaign->fresh()])->call('resume');
        $this->assertSame(AdCampaignStatus::Active, $campaign->fresh()->status);
        Livewire::test('marketing.ad', ['campaign' => $campaign->fresh()])->call('stop');
        $this->assertSame(AdCampaignStatus::Ended, $campaign->fresh()->status);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/cmp-1') && ($r->data()['status'] ?? null) === 'ARCHIVED');
    }

    public function test_discarding_a_never_launched_ad_deletes_it_on_meta(): void
    {
        $this->fakeAds();
        [, , $post] = $this->adsShop();
        $this->createViaForm($post);

        Livewire::test('marketing.ad', ['campaign' => MetaAdCampaign::sole()])->call('discard')->assertRedirect();

        $this->assertSame(0, MetaAdCampaign::count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/cmp-1') && ($r->data()['status'] ?? null) === 'DELETED');
    }

    public function test_results_sync_and_orders_from_the_ad_are_attributed_with_roas(): void
    {
        $this->fakeAds();
        [$shop, $owner, $post, , , $whatsapp] = $this->adsShop();
        $this->createViaForm($post);
        $campaign = MetaAdCampaign::sole();
        Livewire::test('marketing.ad', ['campaign' => $campaign])->set('confirmSpend', true)->call('launch');

        $this->artisan('marketing:sync-ads')->assertSuccessful();
        $campaign->refresh();
        $this->assertSame(2400.5, $campaign->metric('spend'));
        $this->assertSame(42.0, $campaign->metric('conversations'));
        $this->assertSame('ACTIVE', $campaign->effective_status);

        $product = $post->products()->first();
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923331112222', 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now(), 'referral' => ['source_type' => 'ad', 'source_id' => 'ad-1']]);
        WhatsAppOrderItem::create(['shop_id' => $shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 2, 'quoted_unit_price' => 6500]);
        $sale = AcceptWhatsAppOrder::handle($order, [['product_id' => $product->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 6500.0]], 0, null, 13000, $owner->id);

        $results = MarketingStats::adResults($campaign->fresh());
        $this->assertSame(1, $results['orders']);
        $this->assertSame(13000.0, $results['revenue']);
        $this->assertSame(5.42, $results['roas']);
        $this->assertSame(1, (new MarketingStats(now()->subDay(), now()))->adTotals()['orders']);

        CancelSale::handle(Sale::find($sale->id), $owner->id, 'Returned');
        $this->assertSame(0, MarketingStats::adResults($campaign->fresh())['orders']);
        $this->assertSame(0.0, MarketingStats::adResults($campaign->fresh())['revenue']);
        $this->assertSame(0.0, MarketingStats::adResults($campaign->fresh())['roas']);
    }

    public function test_a_campaign_past_its_end_date_is_marked_completed_on_sync(): void
    {
        $this->fakeAds();
        [, , $post] = $this->adsShop();
        $this->createViaForm($post, ['days' => '1']);
        $campaign = MetaAdCampaign::sole();
        Livewire::test('marketing.ad', ['campaign' => $campaign])->set('confirmSpend', true)->call('launch');

        $this->travel(2)->days();
        $this->artisan('marketing:sync-ads')->assertSuccessful();

        $this->assertSame(AdCampaignStatus::Completed, $campaign->fresh()->status);
    }

    public function test_ads_need_the_shop_opt_in_and_their_own_permission(): void
    {
        [$shop, $owner, $post] = $this->adsShop();
        $this->get(route('marketing.ads'))->assertOk();

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Poster', 'permissions' => ['marketing']]);
        $poster = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($poster)->get(route('marketing.ads'))->assertForbidden();
        $this->actingAs($poster)->get(route('marketing.ads.create', ['post' => $post->id]))->assertForbidden();
        $this->actingAs($poster)->get(route('marketing.posts.show', $post))->assertOk()->assertDontSee('Boost with an ad');

        $shop->update(['ads_enabled' => false]);
        $this->actingAs($owner->fresh())->get(route('marketing.ads'))->assertForbidden();
    }

    public function test_another_shops_campaign_is_not_reachable(): void
    {
        $this->fakeAds();
        [, , $postA] = $this->adsShop();
        $this->createViaForm($postA);
        $campaign = MetaAdCampaign::sole();

        [$shopB, $ownerB] = $this->whatsappShop('Shop B', '999');
        $shopB->update(['ads_enabled' => true]);

        $this->actingAs($ownerB->fresh())->get(route('marketing.ads.show', $campaign->id))->assertNotFound();
    }
}
