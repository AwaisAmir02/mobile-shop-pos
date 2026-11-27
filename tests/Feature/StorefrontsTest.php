<?php

namespace Tests\Feature;

use App\Actions\StorefrontConnections;
use App\Enums\StoreDriver;
use App\Enums\StoreOrderStatus;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\StoreProduct;
use App\Support\StockAvailability;
use App\Support\StoreDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\OnlineShopFixtures;
use Tests\Concerns\StorefrontFixtures;
use Tests\TestCase;

/**
 * O9: Shopify (OAuth app, GraphQL, webhooks) and WooCommerce (key handover,
 * REST v3, webhooks) as store-connection drivers. Every platform call is faked
 * to the shape the platform documents; the real stores were never called.
 */
class StorefrontsTest extends TestCase
{
    use OnlineShopFixtures, RefreshDatabase, StorefrontFixtures;

    protected const DOMAIN = 'stylehub.myshopify.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->openOnlineShop('online_brand', ['online_store_enabled' => true]);
        $this->suit->update(['barcode' => 'LS-001']);
        $this->useShopifyApp();
        $this->publicDns();
    }

    /** A GraphQL call's body as Shopify receives it. */
    protected static function gql(Request $request): array
    {
        return json_decode($request->body(), true) ?: [];
    }

    /** Shopify's Admin API as documented: token exchange, then GraphQL answered by what each query asks for. */
    protected function fakeShopify(): void
    {
        Http::fake([
            self::DOMAIN.'/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_123', 'scope' => 'read_products,write_inventory']),
            self::DOMAIN.'/admin/api/2026-10/graphql.json' => function (Request $request) {
                $query = self::gql($request)['query'];

                return Http::response(['data' => match (true) {
                    str_contains($query, 'locations') => ['locations' => ['nodes' => [['id' => 'gid://shopify/Location/1', 'name' => 'Shop']]]],
                    str_contains($query, 'webhookSubscriptionCreate') => ['webhookSubscriptionCreate' => ['webhookSubscription' => ['id' => 'gid://shopify/WebhookSubscription/'.self::gql($request)['variables']['topic']], 'userErrors' => []]],
                    str_contains($query, 'productVariants') => ['productVariants' => ['nodes' => [
                        ['id' => 'gid://shopify/ProductVariant/555', 'sku' => 'LS-001', 'displayName' => 'Lawn Suit - Default', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/777', 'tracked' => true]],
                        ['id' => 'gid://shopify/ProductVariant/556', 'sku' => 'SCARF-9', 'displayName' => 'Silk Scarf', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/778', 'tracked' => true]],
                    ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]],
                    str_contains($query, 'inventorySetQuantities') => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['id' => 'gid://shopify/InventoryAdjustmentGroup/1'], 'userErrors' => []]],
                    default => [],
                }]);
            },
        ]);
    }

    protected function connectShopify(): StoreConnection
    {
        $this->fakeShopify();
        $url = StorefrontConnections::startShopify($this->shop, 'stylehub', $this->owner->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->get(route('storefronts.shopify.callback', $this->shopifyCallback(['code' => 'auth-code', 'shop' => self::DOMAIN, 'state' => $query['state'], 'timestamp' => (string) now()->timestamp])))
            ->assertRedirect(route('settings.index', ['tab' => 'integrations', 'section' => 'online-store']));

        return StoreConnection::where('driver', StoreDriver::Shopify->value)->sole();
    }

    public function test_installing_the_shopify_app_connects_maps_by_barcode_and_subscribes_webhooks(): void
    {
        $url = StorefrontConnections::startShopify($this->shop, 'https://StyleHub.myshopify.com/', $this->owner->id);
        $this->assertStringStartsWith('https://'.self::DOMAIN.'/admin/oauth/authorize?client_id=shopify-client-id&scope=read_products%2Cread_inventory%2Cwrite_inventory%2Cread_orders%2Cread_locations&redirect_uri=', $url);

        $connection = $this->connectShopify();

        $this->assertSame(['shpat_123', 'gid://shopify/Location/1', self::DOMAIN], [$connection->credential('access_token'), $connection->setting('location_id'), $connection->external_domain]);
        $this->assertNotNull($connection->connected_at);
        // MySQL's JSON column keeps its own key order.
        $this->assertEqualsCanonicalizing(['ORDERS_CREATE', 'ORDERS_CANCELLED', 'APP_UNINSTALLED'], array_keys($connection->setting('webhooks')));
        $row = StoreProduct::where('store_connection_id', $connection->id)->sole();
        $this->assertSame([$this->suit->id, 'LS-001', 'gid://shopify/ProductVariant/555', 'gid://shopify/InventoryItem/777', true], [$row->product_id, $row->external_sku, $row->external_ref, $row->inventory_ref, $row->published]);
        $this->assertSame([['sku' => 'SCARF-9', 'name' => 'Silk Scarf']], $connection->setting('unmatched'));
        Http::assertSent(fn (Request $request) => str_contains(self::gql($request)['query'] ?? '', 'webhookSubscriptionCreate') && self::gql($request)['variables']['sub']['uri'] === route('storefronts.shopify.webhook'));
        // The token is never shown or kept in the clear.
        $this->assertStringNotContainsString('shpat_123', (string) \Illuminate\Support\Facades\DB::table('store_connections')->value('credentials'));
    }

    public function test_a_forged_or_replayed_shopify_approval_connects_nothing(): void
    {
        $url = StorefrontConnections::startShopify($this->shop, 'stylehub', $this->owner->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $signed = $this->shopifyCallback(['code' => 'c', 'shop' => self::DOMAIN, 'state' => $query['state'], 'timestamp' => '1']);

        $this->get(route('storefronts.shopify.callback', ['hmac' => 'f00'] + $signed))->assertSessionHas('storefront_error');
        $this->get(route('storefronts.shopify.callback', $signed))->assertSessionHas('storefront_error');
        $this->assertSame(0, StoreConnection::count());
    }

    public function test_stock_is_set_on_shopify_from_the_one_free_to_sell_figure(): void
    {
        $connection = $this->connectShopify();
        $this->postShopify('orders/create', self::DOMAIN, $this->shopifyOrder(9001, 'LS-001', 3))->assertOk();

        app(StoreDelivery::class)->run();

        $this->assertSame(7, StockAvailability::freeFor($this->suit->fresh()));
        Http::assertSent(fn (Request $request) => str_contains(self::gql($request)['query'] ?? '', 'inventorySetQuantities')
            && $request->header('X-Shopify-Access-Token') === ['shpat_123']
            && self::gql($request)['variables']['input']['quantities'] === [['inventoryItemId' => 'gid://shopify/InventoryItem/777', 'locationId' => 'gid://shopify/Location/1', 'quantity' => 7, 'changeFromQuantity' => null]]);
        $this->assertNull($connection->fresh()->last_delivery_error);
    }

    public function test_shopify_orders_arrive_signed_in_the_inbox_and_cancel_and_uninstall_follow(): void
    {
        $connection = $this->connectShopify();

        $this->postShopify('orders/create', self::DOMAIN, $this->shopifyOrder(9001, 'LS-001'), secret: 'wrong')->assertStatus(401);
        $this->postShopify('orders/create', self::DOMAIN, $this->shopifyOrder(9001, 'LS-001'))->assertOk();
        $this->postShopify('orders/create', self::DOMAIN, $this->shopifyOrder(9001, 'LS-001'))->assertOk();
        $this->postShopify('orders/create', self::DOMAIN, $this->shopifyOrder(9002, 'LS-001', 1, 'paid'))->assertOk();

        $cod = StoreOrder::where('external_order_id', '9001')->sole();
        $this->assertSame([StoreOrderStatus::Pending, 'cod', '+92 300 1112223', '200.00', $this->suit->id], [$cod->status, $cod->payment_method, $cod->customer_phone, $cod->shipping_amount, $cod->items->sole()->product_id]);
        $this->assertSame(['prepaid', '3700.00'], [StoreOrder::where('external_order_id', '9002')->sole()->payment_method, StoreOrder::where('external_order_id', '9002')->sole()->amount_paid]);

        $this->postShopify('orders/cancelled', self::DOMAIN, ['id' => 9001, 'cancel_reason' => 'customer'])->assertOk();
        $this->assertSame(StoreOrderStatus::Cancelled, $cod->fresh()->status);

        $this->postShopify('customers/redact', self::DOMAIN, ['orders_to_redact' => [9002]])->assertOk();
        $this->assertNull(StoreOrder::where('external_order_id', '9002')->sole()->customer_phone);

        $this->postShopify('app/uninstalled', self::DOMAIN, ['id' => 1])->assertOk();
        $this->assertSame([null, true], [$connection->fresh()->credentials, $connection->fresh()->removed_at !== null]);
    }

    /** WooCommerce REST v3 as documented. */
    protected function fakeWoo(): void
    {
        Http::fake([
            'mystore.pk/wp-json/wc/v3/webhooks' => Http::response(['id' => 41], 201),
            'mystore.pk/wp-json/wc/v3/products?*' => Http::response([['id' => 81, 'type' => 'simple', 'sku' => 'LS-001', 'name' => 'Lawn Suit'], ['id' => 90, 'type' => 'variable', 'sku' => '', 'name' => 'Kurta']], 200, ['X-WP-TotalPages' => '1']),
            'mystore.pk/wp-json/wc/v3/products/90/variations*' => Http::response([['id' => 91, 'sku' => 'KURTA-M', 'attributes' => [['option' => 'M']]]]),
            'mystore.pk/wp-json/wc/v3/products/batch' => Http::response(['update' => []]),
        ]);
    }

    protected function connectWoo(): StoreConnection
    {
        $this->fakeWoo();
        $url = StorefrontConnections::startWooCommerce($this->shop, 'mystore.pk', $this->owner->id);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->postJson(route('storefronts.woocommerce.keys'), ['key_id' => 5, 'user_id' => $query['user_id'], 'consumer_key' => 'ck_abc', 'consumer_secret' => 'cs_def', 'key_permissions' => 'read_write'])->assertOk();
        $this->get(route('storefronts.woocommerce.return', ['state' => $query['user_id']]))->assertRedirect(route('settings.index', ['tab' => 'integrations', 'section' => 'online-store']));

        return StoreConnection::where('driver', StoreDriver::WooCommerce->value)->sole();
    }

    public function test_woocommerce_hands_over_its_keys_then_webhooks_and_catalogue_are_set_up(): void
    {
        $url = StorefrontConnections::startWooCommerce($this->shop, 'mystore.pk', $this->owner->id);
        $this->assertStringStartsWith('https://mystore.pk/wc-auth/v1/authorize?app_name=', $url);
        $this->assertStringContainsString('scope=read_write', $url);
        $this->assertStringContainsString('callback_url='.urlencode(route('storefronts.woocommerce.keys')), $url);

        $connection = $this->connectWoo();

        $this->assertSame(['ck_abc', 'cs_def', 'https://mystore.pk'], [$connection->credential('consumer_key'), $connection->credential('consumer_secret'), $connection->external_domain]);
        $this->assertNotNull($connection->connected_at);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/wc/v3/webhooks') && $request['topic'] === 'order.created' && $request['secret'] === $connection->signing_secret
            && $request['delivery_url'] === route('storefronts.woocommerce.webhook', $connection->public_id) && $request->hasHeader('Authorization', 'Basic '.base64_encode('ck_abc:cs_def')));
        $this->assertSame('p81', StoreProduct::where('store_connection_id', $connection->id)->sole()->external_ref);
        $this->assertSame([['sku' => 'KURTA-M', 'name' => 'Kurta M']], $connection->setting('unmatched'));

        // Keys for a state we never gave out are refused.
        $this->postJson(route('storefronts.woocommerce.keys'), ['user_id' => str_repeat('z', 40), 'consumer_key' => 'ck', 'consumer_secret' => 'cs'])->assertStatus(400);
    }

    public function test_woocommerce_orders_arrive_signed_and_stock_is_sent_back_in_a_batch(): void
    {
        $connection = $this->connectWoo();
        $secret = $connection->signing_secret;

        $this->call('POST', route('storefronts.woocommerce.webhook', $connection->public_id), [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'webhook_id=41')->assertOk();
        $this->postWoo($connection->public_id, 'order.created', $this->wooOrder(501, 'LS-001'), 'wrong')->assertStatus(401);
        $this->postWoo($connection->public_id, 'order.created', $this->wooOrder(502, 'LS-001', 'pending', 'jazzcash'), $secret)->assertOk();
        $this->postWoo($connection->public_id, 'order.created', $this->wooOrder(501, 'LS-001'), $secret)->assertOk();

        $order = StoreOrder::sole();
        $this->assertSame(['501', 'cod', '250.00', '03334445556', $this->suit->id], [$order->external_order_id, $order->payment_method, $order->shipping_amount, $order->customer_phone, $order->items->sole()->product_id]);

        app(StoreDelivery::class)->run();
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/wc/v3/products/batch') && $request['update'] === [['id' => 81, 'stock_quantity' => 9, 'manage_stock' => true]]);

        $this->postWoo($connection->public_id, 'order.updated', ['status' => 'cancelled'] + $this->wooOrder(501, 'LS-001'), $secret)->assertOk();
        $this->assertSame(StoreOrderStatus::Cancelled, $order->fresh()->status);
    }

    public function test_an_unreachable_or_private_store_is_refused_before_anything_is_called(): void
    {
        app(\App\Support\StoreCallbackGuard::class)->answers = ['intranet.pk' => ['10.0.0.5']];

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        StorefrontConnections::startWooCommerce($this->shop, 'https://intranet.pk', $this->owner->id);
    }

    public function test_only_online_sellers_on_a_plan_with_online_store_can_connect_and_api_screens_stay_api_only(): void
    {
        $this->connectShopify();
        $this->get(route('settings.index', ['tab' => 'integrations', 'section' => 'online-store']))->assertSee('Shopify or WooCommerce store')->assertSee(self::DOMAIN);
        // The own-website API screen doesn't list the Shopify connection as one of its own.
        \Livewire\Livewire::test('settings.online-store')->assertViewHas('connections', fn ($connections) => $connections->isEmpty());

        $retail = \App\Models\Shop::create(['name' => 'Corner', 'business_category' => 'clothing', 'online_store_enabled' => true]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $retail->id, 'is_owner' => true]));
        $this->assertFalse(StorefrontConnections::allowed($retail));
        $this->get(route('storefronts.shopify.callback'))->assertNotFound();
        $this->get(route('settings.index', ['tab' => 'integrations', 'section' => 'online-store']))->assertDontSee('Shopify or WooCommerce store');
    }

    public function test_the_developer_guide_is_public(): void
    {
        auth()->logout();
        $this->get(route('developers.store-api'))->assertOk()->assertSee('Quick start', false)->assertSee('X-HOK-Signature')->assertSee('<table>', false);
    }
}
