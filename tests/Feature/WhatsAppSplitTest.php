<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Addon;
use App\Models\Customer;
use App\Models\CustomerReview;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\ShopChannelConnection;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionPaymentService;
use App\Services\WhatsApp\CustomerNotifications;
use App\Services\WhatsApp\WhatsAppChatbot;
use App\Support\WhatsAppSplitBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\WhatsAppFixtures;
use Tests\TestCase;

class WhatsAppSplitTest extends TestCase
{
    use RefreshDatabase, WhatsAppFixtures;

    protected string $customer = '923001234567';

    /** Runs the production SQL, in the migration's order. */
    protected function backfill(): void
    {
        DB::statement(WhatsAppSplitBackfill::SHOP_COLUMNS_SQL);
        DB::statement(WhatsAppSplitBackfill::PLAN_FLAGS_SQL);
        DB::statement(WhatsAppSplitBackfill::SHOP_OVERRIDES_SQL);

        foreach (WhatsAppSplitBackfill::ADDON_TABLES as $table) {
            DB::statement(WhatsAppSplitBackfill::addonKeysSql($table));
        }
    }

    protected function legacyShop(string $name, bool $whatsapp, ?array $overrides = null): int
    {
        $id = Shop::create(['name' => $name])->id;
        DB::table('shops')->where('id', $id)->update([
            'whatsapp_enabled' => $whatsapp,
            'whatsapp_ordering_enabled' => false,
            'whatsapp_messaging_enabled' => false,
            'feature_overrides' => $overrides === null ? null : json_encode($overrides),
        ]);

        return $id;
    }

    protected function legacyPlan(string $name, array $flags): int
    {
        return DB::table('plans')->insertGetId([
            'name' => $name, 'price' => 1000, 'billing_interval' => 'monthly', 'features' => '[]',
            'feature_flags' => json_encode($flags), 'is_active' => true, 'sort_order' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function legacyAddon(string $name, ?string $featureKey, string $kind = 'feature_unlock'): int
    {
        return DB::table('addons')->insertGetId([
            'name' => $name, 'kind' => $kind, 'feature_key' => $featureKey, 'limit_key' => $featureKey ? null : 'products',
            'quantity' => $featureKey ? null : 100, 'price' => 1500, 'billing' => 'monthly', 'is_active' => true, 'sort_order' => 9,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function flags(int $planId): array
    {
        return json_decode(DB::table('plans')->where('id', $planId)->value('feature_flags'), true);
    }

    public function test_the_backfill_gives_every_old_whatsapp_value_to_both_halves_and_can_run_twice(): void
    {
        $on = $this->legacyShop('On', true, ['whatsapp' => false, 'ads' => true]);
        $off = $this->legacyShop('Off', false);
        $alreadySplit = $this->legacyShop('Split already', true);
        DB::table('shops')->where('id', $alreadySplit)->update(['whatsapp_ordering_enabled' => true]);

        $growth = $this->legacyPlan('Legacy growth', ['whatsapp' => true, 'ads' => false]);
        $starter = $this->legacyPlan('Legacy starter', ['whatsapp' => false]);
        $silent = $this->legacyPlan('Legacy silent', ['barcode' => true]);

        $bundle = $this->legacyAddon('WhatsApp', 'whatsapp');
        $ads = $this->legacyAddon('Ads', 'ads');
        $pack = $this->legacyAddon('Pack', null, 'limit_pack');
        $bought = DB::table('shop_addons')->insertGetId([
            'shop_id' => $on, 'addon_id' => $bundle, 'status' => 'active', 'name' => 'WhatsApp', 'kind' => 'feature_unlock',
            'feature_key' => 'whatsapp', 'price' => 1500, 'billing' => 'monthly', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->backfill();
        $snapshot = fn () => [DB::table('shops')->orderBy('id')->get()->toArray(), DB::table('plans')->orderBy('id')->get()->toArray(), DB::table('addons')->orderBy('id')->get()->toArray(), DB::table('shop_addons')->orderBy('id')->get()->toArray()];
        $afterFirst = $snapshot();

        $shop = fn (int $id) => Shop::find($id);
        $this->assertTrue($shop($on)->whatsapp_ordering_enabled && $shop($on)->whatsapp_messaging_enabled);
        $this->assertFalse($shop($off)->usesWhatsApp());
        $this->assertTrue($shop($alreadySplit)->whatsapp_ordering_enabled);
        $this->assertFalse($shop($alreadySplit)->whatsapp_messaging_enabled, 'A shop whose halves are already set is left alone.');
        $this->assertEquals(['ads' => true, 'whatsapp_ordering' => false, 'whatsapp_messaging' => false], $shop($on)->feature_overrides);

        $this->assertEquals(['ads' => false, 'whatsapp_ordering' => true, 'whatsapp_messaging' => true], $this->flags($growth));
        $this->assertEquals(['whatsapp_ordering' => false, 'whatsapp_messaging' => false], $this->flags($starter));
        $this->assertEquals(['barcode' => true], $this->flags($silent), 'A plan that never mentioned WhatsApp still does not.');

        $this->assertSame([FeatureKey::WhatsAppOrdering, FeatureKey::WhatsAppMessaging], Addon::find($bundle)->feature_keys->all());
        $this->assertSame([FeatureKey::Ads], Addon::find($ads)->feature_keys->all());
        $this->assertNull(Addon::find($pack)->feature_keys);
        $this->assertSame([FeatureKey::WhatsAppOrdering, FeatureKey::WhatsAppMessaging], ShopAddon::find($bought)->feature_keys->all());

        $this->assertSame([], DB::select(WhatsAppSplitBackfill::reconcileSql()));

        $this->backfill();
        $this->assertEquals($afterFirst, $snapshot(), 'Running the SQL again changes nothing.');
    }

    public function test_the_rollback_sql_turns_either_half_back_into_the_old_key(): void
    {
        $on = $this->legacyShop('On', true, ['whatsapp' => true]);
        $growth = $this->legacyPlan('Legacy growth', ['whatsapp' => true]);
        $bundle = $this->legacyAddon('WhatsApp', 'whatsapp');
        $this->backfill();

        // After the split an admin switches messaging off for this shop.
        DB::table('shops')->where('id', $on)->update(['whatsapp_messaging_enabled' => false, 'whatsapp_enabled' => false]);
        DB::table('addons')->where('id', $bundle)->update(['feature_key' => null]);

        DB::statement(WhatsAppSplitBackfill::reverseFlagsSql('plans', 'feature_flags'));
        DB::statement(WhatsAppSplitBackfill::reverseFlagsSql('shops', 'feature_overrides'));
        DB::statement(WhatsAppSplitBackfill::REVERSE_SHOP_COLUMNS_SQL);
        foreach (WhatsAppSplitBackfill::reverseAddonKeysSql('addons') as $sql) {
            DB::statement($sql);
        }

        $this->assertSame(1, (int) DB::table('shops')->where('id', $on)->value('whatsapp_enabled'), 'Ordering is still on, so the old flag is on.');
        $this->assertEquals(['whatsapp' => true], $this->flags($growth));
        $this->assertEquals(['whatsapp' => true], json_decode(DB::table('shops')->where('id', $on)->value('feature_overrides'), true));
        $this->assertSame('whatsapp', DB::table('addons')->where('id', $bundle)->value('feature_key'));
    }

    public function test_each_screen_follows_its_own_half(): void
    {
        [$shop, $owner] = $this->whatsappShop();

        $shop->update(['whatsapp_messaging_enabled' => false]);
        $this->actingAs($owner->fresh())->get(route('whatsapp-orders.index'))->assertOk();
        $this->actingAs($owner->fresh())->get(route('whatsapp-messages.index'))->assertForbidden();
        $this->actingAs($owner->fresh())->get(route('dashboard'))
            ->assertSee(route('whatsapp-orders.index'), false)
            ->assertDontSee(route('whatsapp-messages.index'), false);

        $shop->update(['whatsapp_ordering_enabled' => false, 'whatsapp_messaging_enabled' => true]);
        $this->actingAs($owner->fresh())->get(route('whatsapp-orders.index'))->assertForbidden();
        $this->actingAs($owner->fresh())->get(route('whatsapp-messages.index'))->assertOk();
        $this->actingAs($owner->fresh())->get(route('marketing.posts'))->assertOk();
        $this->actingAs($owner->fresh())->get(route('dashboard'))
            ->assertDontSee(route('whatsapp-orders.index'), false)
            ->assertSee(route('whatsapp-messages.index'), false);

        $product = $this->listedProduct($shop);
        Livewire::actingAs($owner->fresh())->test('products.index')->call('toggleWhatsAppListing', $product->id)->assertForbidden();
        Livewire::actingAs($owner->fresh())->test('settings.integrations')->assertSet('section', 'whatsapp')->assertDontSee('is not enabled for your shop');
    }

    public function test_order_updates_need_ordering_and_every_other_message_needs_messaging(): void
    {
        $this->fakeGraph();
        [$shop, $owner, $connection] = $this->whatsappShop();
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'shop_channel_connection_id' => $connection->id, 'wa_id' => $this->customer, 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Accepted, 'submitted_at' => now(), 'quoted_total' => 1500]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
        $notifications = app(CustomerNotifications::class);

        $shop->update(['whatsapp_messaging_enabled' => false]);
        $notifications->orderFulfillment($order, FulfillmentStatus::Packed, $owner->id);
        $this->assertCount(1, $this->sentBodies());

        try {
            $notifications->paymentReminder($customer, $owner->id);
            $this->fail('A payment reminder needs messaging.');
        } catch (RuntimeException $e) {
            $this->assertSame('WhatsApp messaging is not enabled for this shop.', $e->getMessage());
        }

        $shop->update(['whatsapp_ordering_enabled' => false, 'whatsapp_messaging_enabled' => true]);
        $this->expectExceptionMessage('WhatsApp ordering is not enabled for this shop.');
        $notifications->orderFulfillment($order, FulfillmentStatus::OutForDelivery, $owner->id);
    }

    public function test_review_requests_go_out_only_with_messaging_on(): void
    {
        $this->fakeGraph();
        [$shop, , $connection] = $this->whatsappShop();
        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'shop_channel_connection_id' => $connection->id, 'wa_id' => $this->customer, 'last_inbound_at' => now()]);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Accepted, 'submitted_at' => now(), 'quoted_total' => 1500, 'fulfillment_status' => FulfillmentStatus::Delivered, 'delivered_at' => now()]);
        $this->travel(25)->hours();
        WhatsAppContact::query()->update(['last_inbound_at' => now()]);

        $shop->update(['whatsapp_messaging_enabled' => false]);
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertNull($order->fresh()->review_requested_at);

        $shop->update(['whatsapp_ordering_enabled' => false, 'whatsapp_messaging_enabled' => true]);
        $this->artisan('whatsapp:follow-ups')->assertSuccessful();
        $this->assertNotNull($order->fresh()->review_requested_at);
    }

    public function test_with_ordering_off_the_bot_still_takes_review_replies_but_no_orders(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();
        [$shop, , $connection] = $this->whatsappShop();
        $this->listedProduct($shop);
        $shop->update(['whatsapp_ordering_enabled' => false]);

        $this->postWebhook($this->webhookBody($connection->external_id, [$this->textMessage($this->customer, 'hi')]))->assertOk();
        $reply = collect($this->sentBodies())->last();
        $this->assertStringContainsString('isn\'t taking orders on WhatsApp right now', $reply['interactive']['body']['text']);
        $this->assertSame(['agent'], array_column(array_column($reply['interactive']['action']['buttons'], 'reply'), 'id'));

        $bot = new WhatsAppChatbot($connection->fresh());
        foreach ([$this->textMessage($this->customer, 'catalog'), $this->replyMessage($this->customer, 'browse'), $this->replyMessage($this->customer, 'checkout'), $this->textMessage($this->customer, 'charger')] as $message) {
            $bot->handleInbound($message, 'Ali');
        }
        $this->assertSame(0, WhatsAppOrder::withoutGlobalScopes()->count());

        $contact = WhatsAppContact::withoutGlobalScopes()->sole();
        WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Accepted, 'submitted_at' => now(), 'quoted_total' => 1500, 'review_requested_at' => now()]);

        $bot->handleInbound($this->replyMessage($this->customer, 'rate:5'), 'Ali');
        $bot->handleInbound($this->textMessage($this->customer, 'Great service'), 'Ali');
        $review = CustomerReview::withoutGlobalScopes()->sole();
        $this->assertSame([5, 'Great service'], [$review->rating, $review->comment]);

        $bot->handleInbound($this->textMessage($this->customer, 'agent'), 'Ali');
        $this->assertStringContainsString('the shop will reply to you here personally', collect($this->sentBodies())->last()['text']['body']);
    }

    public function test_the_webhook_reaches_a_shop_with_only_one_half_on(): void
    {
        $this->fakeGraph();
        $this->whatsappIntegration();

        foreach (['whatsapp_ordering_enabled', 'whatsapp_messaging_enabled'] as $i => $onlyHalf) {
            [$shop, , $connection] = $this->whatsappShop('Shop '.$i, '55'.$i);
            $shop->update(['whatsapp_ordering_enabled' => false, 'whatsapp_messaging_enabled' => false, $onlyHalf => true]);

            $this->postWebhook($this->webhookBody($connection->external_id, [$this->textMessage('92300000000'.$i, 'hi')]))->assertOk();
            $this->assertSame(1, WhatsAppContact::withoutGlobalScopes()->where('shop_id', $shop->id)->count(), $onlyHalf);
        }
    }

    public function test_a_bundle_unlock_switches_on_both_halves_and_a_single_unlock_one(): void
    {
        $plan = Plan::create(['name' => 'Bare', 'price' => 1000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => [], 'feature_flags' => ['whatsapp_ordering' => false, 'whatsapp_messaging' => false]]);
        $shop = Shop::create(['name' => 'Bundle', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(3)->toDateString()]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $confirm = fn () => app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $admin->id);

        $messaging = Addon::create(['name' => 'Messaging', 'kind' => 'feature_unlock', 'feature_keys' => ['whatsapp_messaging'], 'price' => 800, 'billing' => 'monthly']);
        app(AddonService::class)->purchase($shop->fresh(), $messaging->fresh(), $owner->id);
        $confirm();
        $this->assertSame([false, true], [$shop->fresh()->whatsapp_ordering_enabled, $shop->fresh()->whatsapp_messaging_enabled]);

        $bundle = Addon::create(['name' => 'WhatsApp', 'kind' => 'feature_unlock', 'feature_keys' => ['whatsapp_ordering', 'whatsapp_messaging'], 'price' => 1500, 'billing' => 'monthly']);
        $this->assertSame('WhatsApp ordering & WhatsApp messaging switched on', $bundle->fresh()->grants());
        app(AddonService::class)->purchase($shop->fresh(), $bundle->fresh(), $owner->id);
        $confirm();
        $this->assertTrue($shop->fresh()->whatsapp_ordering_enabled && $shop->fresh()->whatsapp_messaging_enabled);

        $sources = collect(app(Entitlements::class)->features($shop->fresh()))->mapWithKeys(fn ($row) => [$row['key']->value => $row['source']]);
        $this->assertSame(['addon', 'addon'], [$sources['whatsapp_ordering'], $sources['whatsapp_messaging']]);

        // A plan with only one half still sells the bundle; with both it doesn't.
        $plan->update(['feature_flags' => ['whatsapp_ordering' => true, 'whatsapp_messaging' => false]]);
        $this->assertFalse(app(Entitlements::class)->planIncludesAll($shop->fresh(), $bundle->fresh()->feature_keys));
        $plan->update(['feature_flags' => ['whatsapp_ordering' => true, 'whatsapp_messaging' => true]]);
        $this->expectExceptionMessage('Your plan already includes WhatsApp ordering & WhatsApp messaging.');
        app(AddonService::class)->purchase($shop->fresh(), $bundle->fresh(), $owner->id);
    }

    public function test_the_admin_builds_a_bundle_from_the_addon_form(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'None')->set('kind', 'feature_unlock')->set('price', '100')->call('save')
            ->assertHasErrors(['featureKeys' => 'Tick at least one feature.']);

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'WhatsApp')->set('kind', 'feature_unlock')
            ->set('featureKeys', ['whatsapp_messaging', 'whatsapp_ordering'])->set('price', '1500')->call('save')->assertHasNoErrors();

        $addon = Addon::where('name', 'WhatsApp')->sole();
        $this->assertSame([FeatureKey::WhatsAppOrdering, FeatureKey::WhatsAppMessaging], $addon->feature_keys->all(), 'Stored in catalogue order.');

        Livewire::test('admin.addons')->call('openEdit', $addon->id)->assertSet('featureKeys', ['whatsapp_ordering', 'whatsapp_messaging']);
    }
}
