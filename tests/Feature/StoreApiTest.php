<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\StoreConnections;
use App\Enums\FeatureKey;
use App\Models\Addon;
use App\Models\MainCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StoreApiToken;
use App\Models\StoreConnection;
use App\Models\StoreProduct;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Enums\WhatsAppOrderStatus;
use App\Services\Billing\Entitlements;
use App\Support\ApiShopContext;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StoreApiTest extends TestCase
{
    use RefreshDatabase;

    protected function storeShop(string $name = 'Ali Mobiles', array $attributes = []): Shop
    {
        $shop = Shop::create(['name' => $name, 'online_store_enabled' => true] + $attributes);
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => $name.' Goods', 'slug' => 'goods']);

        return $shop;
    }

    /** @return array{connection: StoreConnection, token: string, secret: string} */
    protected function connect(Shop $shop): array
    {
        return StoreConnections::create($shop->id, 'myshop.pk', null);
    }

    protected function item(Shop $shop, array $attributes = []): Product
    {
        return Product::create($attributes + [
            'shop_id' => $shop->id, 'type' => 'goods', 'name' => 'Charger', 'price' => 500, 'cost_price' => 300,
            'stock_quantity' => 0, 'unit' => 'piece', 'details' => ['category' => null],
        ]);
    }

    protected function batch(Product $product, int $quantity, array $attributes = []): StockIn
    {
        $product->increment('stock_quantity', $quantity);

        return StockIn::create($attributes + [
            'shop_id' => $product->shop_id, 'product_id' => $product->id, 'product_name' => $product->name,
            'quantity' => $quantity, 'quantity_remaining' => $quantity, 'unit' => $product->unit,
            'cost_price' => 300, 'selling_price' => 550, 'total_cost' => 300 * $quantity,
            'amount_paid' => 0, 'payment_status' => 'unpaid', 'stock_date' => now()->toDateString(),
        ]);
    }

    protected function api(string $token, string $path): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/channel/v1/'.ltrim($path, '/'));
    }

    protected function published(Shop $shop, array $attributes = [], int $stock = 0): array
    {
        $created = $this->connect($shop);
        $product = $this->item($shop, $attributes);

        if ($stock > 0) {
            $this->batch($product, $stock);
        }

        StoreConnections::publish($created['connection'], collect([$product->fresh()]));

        return $created + ['product' => $product->fresh()];
    }

    protected function waitingWhatsAppOrder(Product $product, int $quantity): WhatsAppOrder
    {
        $contact = WhatsAppContact::withoutGlobalScopes()->forceCreate(['shop_id' => $product->shop_id, 'wa_id' => '923001234567']);
        $order = WhatsAppOrder::withoutGlobalScopes()->create(['shop_id' => $product->shop_id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'submitted_at' => now()]);
        WhatsAppOrderItem::withoutGlobalScopes()->create(['shop_id' => $product->shop_id, 'whatsapp_order_id' => $order->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => $quantity, 'unit' => 'piece', 'quoted_unit_price' => 500]);

        return $order;
    }

    // ── Tokens ─────────────────────────────────────────────────────────

    public function test_a_token_is_stored_only_as_a_hash(): void
    {
        $created = $this->connect($this->storeShop());
        $row = StoreApiToken::sole();

        $this->assertStringStartsWith('hok_', $created['token']);
        $this->assertSame(hash('sha256', $created['token']), $row->token_hash);
        $this->assertStringNotContainsString($created['token'], json_encode($row->getAttributes()));
        $this->assertSame($created['secret'], $created['connection']->fresh()->signing_secret);
        $this->assertNotSame($created['secret'], $created['connection']->fresh()->getAttributes()['signing_secret']);
    }

    public function test_missing_wrong_and_replaced_tokens_are_refused_in_the_documented_shape(): void
    {
        $shop = $this->storeShop();
        $created = $this->connect($shop);

        $this->getJson('/api/channel/v1/me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->api('hok_wrong', 'me')->assertStatus(401)->assertJsonPath('error.code', 'unauthenticated');
        $this->api($created['token'], 'me')->assertOk();

        $new = StoreConnections::issueToken($created['connection']);

        $this->api($created['token'], 'me')->assertStatus(401);
        $this->api($new, 'me')->assertOk();
    }

    public function test_a_removed_connections_token_stops_working(): void
    {
        $created = $this->connect($this->storeShop());

        StoreConnections::remove($created['connection']);

        $this->api($created['token'], 'me')->assertStatus(401);
    }

    public function test_a_token_only_reaches_its_scopes(): void
    {
        $created = $this->connect($this->storeShop());
        StoreApiToken::query()->update(['scopes' => json_encode(['inventory:read'])]);

        $this->api($created['token'], 'products')->assertStatus(403)->assertJsonPath('error.code', 'insufficient_scope');
        $this->api($created['token'], 'inventory')->assertOk();
        $this->api($created['token'], 'me')->assertOk();
    }

    public function test_a_paused_connection_refuses_data_but_can_still_ask_why(): void
    {
        $created = $this->connect($this->storeShop());

        StoreConnections::pause($created['connection']);

        $this->api($created['token'], 'products')->assertStatus(403)->assertJsonPath('error.code', 'connection_paused');
        $this->api($created['token'], 'inventory')->assertStatus(403)->assertJsonPath('error.code', 'connection_paused');
        $this->api($created['token'], 'me')->assertOk()->assertJsonPath('data.status', 'paused');

        StoreConnections::resume($created['connection']);
        $this->api($created['token'], 'products')->assertOk();
    }

    public function test_the_shop_losing_the_feature_pauses_the_connection(): void
    {
        $shop = $this->storeShop();
        $created = $this->connect($shop);

        $shop->update(['online_store_enabled' => false]);

        $this->api($created['token'], 'inventory')->assertStatus(403)->assertJsonPath('error.code', 'connection_paused');
        $this->api($created['token'], 'me')->assertJsonPath('data.status', 'paused');
    }

    public function test_a_connection_cant_be_created_or_resumed_without_the_feature(): void
    {
        $off = Shop::create(['name' => 'Off']);

        $this->expectException(ValidationException::class);

        StoreConnections::create($off->id, 'x', null);
    }

    public function test_me_describes_the_connection(): void
    {
        $created = $this->connect($this->storeShop());

        $this->api($created['token'], 'me')
            ->assertOk()
            ->assertJsonPath('data.connection_id', $created['connection']->public_id)
            ->assertJsonPath('data.shop_name', 'Ali Mobiles')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.price_sync', false)
            ->assertJsonPath('data.scopes', StoreApiToken::SCOPES);
    }

    // ── Tenant isolation ───────────────────────────────────────────────

    public function test_one_shops_token_cant_reach_another_shops_data_on_any_endpoint(): void
    {
        $a = $this->published($this->storeShop('Shop A'), ['name' => 'A Secret'], 5);
        $b = $this->connect($this->storeShop('Shop B'));
        $productA = $a['product'];

        $this->api($b['token'], "products/{$productA->id}")->assertStatus(404);
        $this->api($b['token'], 'products')->assertOk()->assertJsonCount(0, 'data')->assertDontSee('A Secret');
        $this->api($b['token'], 'products?updated_since='.urlencode(now()->subYear()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
        $this->api($b['token'], 'inventory')->assertOk()->assertJsonCount(0, 'data');
        $this->api($b['token'], 'inventory?updated_since='.urlencode(now()->subYear()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
        $this->api($b['token'], 'me')->assertJsonPath('data.shop_name', 'Shop B')->assertDontSee('Shop A');
    }

    public function test_every_route_in_the_api_needs_a_store_token(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $this->assertNotEmpty(
                collect($route->gatherMiddleware())->filter(fn ($name) => is_string($name) && str_starts_with($name, 'store.token'))->all(),
                "{$route->uri()} doesn't name store.token, so it isn't scoped to one shop."
            );
        }
    }

    public function test_a_categorys_name_comes_from_the_tokens_own_shop(): void
    {
        // Same slug in two shops: the relation is by slug, so only the shop scope tells them apart.
        $other = $this->storeShop('Other');
        $created = $this->published($this->storeShop('Mine'));

        $this->assertNotNull($other);

        $this->api($created['token'], 'products')->assertOk()->assertJsonPath('data.0.category', 'Mine Goods');
    }

    public function test_the_context_scopes_queries_and_writes_and_ends_with_the_request(): void
    {
        $a = $this->storeShop('A');
        $b = $this->storeShop('B');
        $itemA = $this->item($a);
        $itemB = $this->item($b);

        ApiShopContext::current()->run($a->id, function () use ($itemA, $itemB, $b) {
            $this->assertNotNull(Product::find($itemA->id));
            $this->assertNull(Product::find($itemB->id));

            $this->assertSame($itemA->shop_id, Product::create(['type' => 'goods', 'name' => 'Filled', 'price' => 1, 'details' => []])->shop_id);

            try {
                Product::create(['shop_id' => $b->id, 'type' => 'goods', 'name' => 'Crossed', 'price' => 1, 'details' => []]);
                $this->fail('A write to another shop should have been refused.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('another shop', $e->getMessage());
            }
        });

        $this->assertNull(ApiShopContext::current()->shopId());
        $this->assertNotNull(Product::find($itemB->id));

        try {
            ApiShopContext::current()->run($a->id, fn () => throw new \LogicException('boom'));
        } catch (\LogicException) {
        }

        $this->assertNull(ApiShopContext::current()->shopId());
    }

    public function test_the_context_outranks_a_signed_in_user(): void
    {
        $a = $this->storeShop('A');
        $b = $this->storeShop('B');
        $itemA = $this->item($a);
        $this->actingAs(User::factory()->create(['shop_id' => $b->id, 'is_owner' => true]));

        $this->assertNull(Product::find($itemA->id));
        ApiShopContext::current()->run($a->id, fn () => $this->assertNotNull(Product::find($itemA->id)));
        $this->assertNull(Product::find($itemA->id));
    }

    // ── Products ───────────────────────────────────────────────────────

    public function test_products_lists_only_what_was_published_on_this_connection(): void
    {
        $shop = $this->storeShop();
        $created = $this->published($shop, ['name' => 'On sale', 'barcode' => '12345']);
        $this->item($shop, ['name' => 'Not published']);
        $second = $this->published($shop, ['name' => 'Elsewhere']);

        $this->api($created['token'], 'products')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $created['product']->id)
            ->assertJsonPath('data.0.name', 'On sale')
            ->assertJsonPath('data.0.barcode', '12345')
            ->assertJsonPath('data.0.group', null)
            ->assertJsonPath('data.0.price', null)
            ->assertJsonPath('data.0.published', true)
            ->assertJsonPath('meta.next_cursor', null);

        $this->api($second['token'], 'products')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Elsewhere');
        $this->api($created['token'], "products/{$created['product']->id}")->assertOk()->assertJsonPath('data.name', 'On sale');
        $this->api($created['token'], "products/{$second['product']->id}")->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->api($created['token'], 'products/not-a-number')->assertStatus(404);
    }

    public function test_times_carry_the_pakistan_offset(): void
    {
        $created = $this->published($this->storeShop());

        $this->assertStringEndsWith('+05:00', $this->api($created['token'], 'products')->json('data.0.updated_at'));
        $this->assertStringEndsWith('+05:00', $this->api($created['token'], 'me')->json('data.server_time'));
    }

    public function test_a_variant_shows_its_group(): void
    {
        $shop = $this->storeShop();
        $group = ProductGroup::create(['shop_id' => $shop->id, 'name' => 'Charger 20W', 'type' => 'goods']);
        $created = $this->published($shop, ['name' => 'Charger 20W - Black', 'product_group_id' => $group->id, 'variant_label' => 'Black']);

        $this->api($created['token'], 'products')
            ->assertJsonPath('data.0.group.id', $group->id)
            ->assertJsonPath('data.0.group.name', 'Charger 20W')
            ->assertJsonPath('data.0.group.variant_label', 'Black');
    }

    public function test_prices_go_out_only_when_the_shop_syncs_them_and_follow_the_next_batch(): void
    {
        $created = $this->published($this->storeShop(), ['price' => 500], 2);

        $this->api($created['token'], 'products')->assertJsonPath('data.0.price', null);

        StoreConnections::updateSettings($created['connection'], true, 0);

        $this->api($created['token'], 'products')->assertJsonPath('data.0.price', '550.00');
        $this->api($created['token'], "products/{$created['product']->id}")->assertJsonPath('data.price', '550.00');
        $this->api($created['token'], 'me')->assertJsonPath('data.price_sync', true);

        $this->batch($created['product'], 1, ['selling_price' => 700, 'stock_date' => now()->addDay()->toDateString()]);
        CreateSale::handle([['product_id' => $created['product']->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, null, now()->toDateString(), 1100.0, $created['product']->shop_id);

        $this->api($created['token'], 'products')->assertJsonPath('data.0.price', '700.00');
    }

    public function test_weighed_imei_sim_and_archived_products_cant_be_published(): void
    {
        $shop = $this->storeShop();
        $connection = $this->connect($shop)['connection'];

        foreach ([
            'weighed' => ['unit' => 'kg'],
            'imei' => ['type' => 'mobile', 'details' => ['imei' => '123456789012345', 'brand' => 'X']],
            'sim' => ['type' => 'sim', 'details' => []],
            'archived' => ['archived_at' => now()],
        ] as $name => $attributes) {
            try {
                StoreConnections::publish($connection, collect([$this->item($shop, ['name' => $name] + $attributes)]));
                $this->fail("{$name} shouldn't have been published.");
            } catch (ValidationException $e) {
                $this->assertStringContainsString("can't be published", $e->validator->errors()->first());
            }
        }

        $this->assertSame(0, StoreProduct::count());
    }

    public function test_the_query_and_the_single_product_check_agree_on_what_can_be_published(): void
    {
        $shop = $this->storeShop();

        $products = collect([
            $this->item($shop, ['name' => 'Plain']),
            $this->item($shop, ['name' => 'No details', 'details' => null]),
            $this->item($shop, ['name' => 'Null imei', 'type' => 'mobile', 'details' => ['brand' => 'X', 'imei' => null]]),
            $this->item($shop, ['name' => 'Empty imei', 'type' => 'mobile', 'details' => ['brand' => 'X', 'imei' => '']]),
            $this->item($shop, ['name' => 'Real imei', 'type' => 'mobile', 'details' => ['brand' => 'X', 'imei' => '35']]),
            $this->item($shop, ['name' => 'Weighed', 'unit' => 'kg']),
            $this->item($shop, ['name' => 'Sim', 'type' => 'sim']),
            $this->item($shop, ['name' => 'Archived', 'archived_at' => now()]),
        ]);

        $byQuery = Product::where('shop_id', $shop->id)->publishableOnline()->pluck('name')->sort()->values()->all();
        $byCheck = $products->map->fresh()->filter(fn (Product $product) => $product->blocksOnlinePublishing() === null)->pluck('name')->sort()->values()->all();

        $this->assertSame(['Empty imei', 'No details', 'Null imei', 'Plain'], $byQuery);
        $this->assertSame($byCheck, $byQuery);
    }

    public function test_a_product_that_stops_qualifying_drops_out_and_shows_as_unpublished_in_changes(): void
    {
        $created = $this->published($this->storeShop());
        $since = urlencode(now()->subMinute()->toIso8601String());

        $created['product']->update(['archived_at' => now()]);

        $this->api($created['token'], 'products')->assertJsonCount(0, 'data');
        $this->api($created['token'], "products/{$created['product']->id}")->assertStatus(404);
        $this->api($created['token'], 'inventory')->assertJsonCount(0, 'data');
        $this->api($created['token'], "products?updated_since={$since}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.published', false);
    }

    public function test_unpublishing_appears_in_changes(): void
    {
        $created = $this->published($this->storeShop());
        $this->travel(1)->hours();
        $since = urlencode(now()->toIso8601String());

        $this->api($created['token'], "products?updated_since={$since}")->assertJsonCount(0, 'data');

        StoreConnections::unpublish($created['connection'], $created['product']);

        $this->api($created['token'], 'products')->assertJsonCount(0, 'data');
        $this->api($created['token'], "products?updated_since={$since}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.published', false);
    }

    public function test_paging_walks_everything_exactly_once(): void
    {
        $shop = $this->storeShop();
        $created = $this->connect($shop);
        $products = collect(range(1, 5))->map(fn (int $i) => $this->item($shop, ['name' => "Item {$i}"]));
        StoreConnections::publish($created['connection'], $products);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->api($created['token'], 'products?limit=2'.($cursor ? "&cursor={$cursor}" : ''))->assertOk();
            $seen = [...$seen, ...array_column($response->json('data'), 'id')];
            $cursor = $response->json('meta.next_cursor');
            $pages++;
        } while ($cursor !== null);

        $this->assertSame(3, $pages);
        $this->assertSame($products->pluck('id')->all(), $seen);
    }

    public function test_bad_query_values_get_a_validation_error(): void
    {
        $created = $this->connect($this->storeShop());

        foreach (['limit=0', 'limit=501', 'limit=abc', 'cursor=@@@', 'updated_since=yesterdayish'] as $query) {
            foreach (['products', 'inventory'] as $endpoint) {
                $this->api($created['token'], "{$endpoint}?{$query}")->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
            }
        }
    }

    public function test_an_unencoded_plus_in_the_time_is_forgiven(): void
    {
        $created = $this->published($this->storeShop());
        $time = now()->subHour()->timezone('Asia/Karachi')->format('Y-m-d\TH:i:sP');

        $this->assertStringContainsString('+05:00', $time);
        $this->api($created['token'], "products?updated_since={$time}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_external_skus_are_unique_on_a_connection_and_shown(): void
    {
        $shop = $this->storeShop();
        $created = $this->published($shop);
        $other = $this->item($shop, ['name' => 'Other']);

        StoreConnections::setExternalSku($created['connection'], $created['product'], '  SKU-1 ');
        $this->api($created['token'], 'products')->assertJsonPath('data.0.external_sku', 'SKU-1');

        try {
            StoreConnections::setExternalSku($created['connection'], $other, 'SKU-1');
            $this->fail('A second product took the same code.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already uses', $e->validator->errors()->first());
        }

        StoreConnections::setExternalSku($created['connection'], $created['product'], ' ');
        $this->api($created['token'], 'products')->assertJsonPath('data.0.external_sku', null);

        StoreConnections::setExternalSku($created['connection'], $other, 'SKU-1');
        $this->assertSame('SKU-1', StoreProduct::where('product_id', $other->id)->sole()->external_sku);
        $this->assertFalse(StoreProduct::where('product_id', $other->id)->sole()->published);
    }

    // ── Inventory ──────────────────────────────────────────────────────

    public function test_available_is_stock_as_an_absolute_figure(): void
    {
        $created = $this->published($this->storeShop(), [], 7);

        $this->api($created['token'], 'inventory')
            ->assertOk()
            ->assertJsonPath('data.0.product_id', $created['product']->id)
            ->assertJsonPath('data.0.available', 7);

        CreateSale::handle([['product_id' => $created['product']->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, null, now()->toDateString(), 1100.0, $created['product']->shop_id);

        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 5);
    }

    public function test_expired_stock_is_never_offered_and_matches_sellable_stock(): void
    {
        $shop = $this->storeShop('Pharmacy', ['expiry_tracking_enabled' => true]);
        $created = $this->published($shop, [], 4);
        $this->batch($created['product'], 3, ['expiry_date' => now()->subDays(2)->toDateString()]);
        $this->batch($created['product'], 2, ['expiry_date' => now()->addDays(10)->toDateString()]);

        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 6);
        $this->assertSame(6.0, (float) $created['product']->fresh()->sellableStock());
        $this->assertSame(9.0, (float) Quantity::normalize($created['product']->fresh()->stock_quantity));
    }

    public function test_expiry_dates_are_ignored_for_a_shop_that_doesnt_track_them(): void
    {
        $created = $this->published($this->storeShop(), [], 0);
        $this->batch($created['product'], 3, ['expiry_date' => now()->subDays(2)->toDateString()]);

        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 3);
    }

    public function test_stock_held_for_a_waiting_whatsapp_order_is_held_back(): void
    {
        $created = $this->published($this->storeShop(), [], 5);
        $order = $this->waitingWhatsAppOrder($created['product'], 2);

        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 3);

        $order->update(['status' => WhatsAppOrderStatus::Rejected]);

        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 5);
    }

    public function test_the_buffer_holds_stock_back_and_available_never_goes_below_zero(): void
    {
        $created = $this->published($this->storeShop(), [], 5);

        StoreConnections::updateSettings($created['connection'], false, 2);
        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 3);

        StoreConnections::updateSettings($created['connection'], false, 50);
        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 0);
    }

    public function test_reading_changes_nothing(): void
    {
        $created = $this->published($this->storeShop(), [], 5);
        $before = [$created['product']->fresh()->stock_quantity, StockIn::sole()->quantity_remaining, Product::max('updated_at')];

        $this->api($created['token'], 'inventory')->assertOk();
        $this->api($created['token'], 'products')->assertOk();

        $this->assertSame($before, [$created['product']->fresh()->stock_quantity, StockIn::sole()->quantity_remaining, Product::max('updated_at')]);
    }

    public function test_sequences_only_go_up(): void
    {
        $created = $this->published($this->storeShop(), [], 5);

        $first = $this->api($created['token'], 'inventory')->json('data.0.sequence');
        usleep(2000);
        $second = $this->api($created['token'], 'inventory')->json('data.0.sequence');

        $this->assertIsInt($first);
        $this->assertGreaterThan($first, $second);
    }

    public function test_inventory_changes_since_include_stock_moves_and_leave_out_the_rest(): void
    {
        $shop = $this->storeShop();
        $created = $this->published($shop, ['name' => 'Moves'], 5);
        $still = $this->item($shop, ['name' => 'Still']);
        StoreConnections::publish($created['connection'], collect([$still]));

        $this->travel(1)->hours();
        $since = urlencode(now()->toIso8601String());
        $this->travel(1)->minutes();

        $this->api($created['token'], "inventory?updated_since={$since}")->assertJsonCount(0, 'data');

        CreateSale::handle([['product_id' => $created['product']->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, null, now()->toDateString(), 550.0, $shop->id);

        $this->api($created['token'], "inventory?updated_since={$since}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.product_id', $created['product']->id)
            ->assertJsonPath('data.0.available', 4);
    }

    public function test_a_batch_expiring_overnight_appears_in_changes_with_no_write_at_all(): void
    {
        $this->travelTo(now()->timezone('Asia/Karachi')->setTime(12, 0));
        $shop = $this->storeShop('Pharmacy', ['expiry_tracking_enabled' => true]);
        $created = $this->published($shop, [], 0);
        $this->batch($created['product'], 3, ['expiry_date' => now()->timezone('Asia/Karachi')->toDateString()]);

        $this->travel(2)->hours();
        $since = urlencode(now()->toIso8601String());
        $this->travel(1)->minutes();

        $this->api($created['token'], "inventory?updated_since={$since}")->assertJsonCount(0, 'data');
        $this->api($created['token'], 'inventory')->assertJsonPath('data.0.available', 3);

        $this->travel(1)->days();

        $this->api($created['token'], "inventory?updated_since={$since}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.available', 0);
    }

    public function test_a_whatsapp_order_arriving_appears_in_changes(): void
    {
        $created = $this->published($this->storeShop(), [], 5);

        $this->travel(1)->hours();
        $since = urlencode(now()->toIso8601String());
        $this->travel(1)->minutes();

        $this->waitingWhatsAppOrder($created['product'], 1);

        $this->api($created['token'], "inventory?updated_since={$since}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.available', 4);
    }

    // ── Errors and limits ──────────────────────────────────────────────

    public function test_unknown_addresses_and_methods_answer_in_the_documented_shape(): void
    {
        $this->getJson('/api/channel/v1/nothing-here')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->get('/api/channel/v1/nothing-here')->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->postJson('/api/channel/v1/me')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_too_many_requests_are_limited_in_the_documented_shape(): void
    {
        $created = $this->connect($this->storeShop());

        foreach (range(1, 300) as $i) {
            $this->api($created['token'], 'me')->assertOk();
        }

        $response = $this->api($created['token'], 'me')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
        $this->assertTrue($response->headers->has('Retry-After'));
    }

    public function test_the_last_used_time_isnt_written_on_every_request(): void
    {
        $created = $this->connect($this->storeShop());

        $this->assertNull(StoreApiToken::sole()->last_used_at);

        $this->api($created['token'], 'me');
        $first = StoreApiToken::sole()->last_used_at;
        $this->assertNotNull($first);

        $this->travel(10)->seconds();
        $this->api($created['token'], 'me');
        $this->assertEquals($first, StoreApiToken::sole()->last_used_at);

        $this->travel(2)->minutes();
        $this->api($created['token'], 'me');
        $this->assertTrue(StoreApiToken::sole()->last_used_at->gt($first));
    }

    // ── Secrets ────────────────────────────────────────────────────────

    public function test_a_new_secret_keeps_the_old_one_for_a_day(): void
    {
        $created = $this->connect($this->storeShop());

        $new = StoreConnections::rotateSecret($created['connection']);
        $fresh = $created['connection']->fresh();

        $this->assertSame($new, $fresh->signing_secret);
        $this->assertSame($created['secret'], $fresh->previous_signing_secret);
        $this->assertTrue($fresh->previous_secret_expires_at->between(now()->addHours(23), now()->addHours(25)));
    }

    // ── Plans ──────────────────────────────────────────────────────────

    public function test_it_is_in_commerce_and_explicitly_off_elsewhere_and_sold_as_an_add_on(): void
    {
        $this->assertTrue(Plan::where('name', 'Commerce')->sole()->feature_flags['online_store']);
        $this->assertFalse(Plan::where('name', 'Starter')->sole()->feature_flags['online_store']);
        $this->assertFalse(Plan::where('name', 'Growth')->sole()->feature_flags['online_store']);

        $addon = Addon::where('name', 'Online Store')->sole();
        $this->assertSame([FeatureKey::OnlineStore], $addon->feature_keys->all());
        $this->assertSame('1000.00', $addon->price);
    }

    public function test_a_downgrade_turns_it_off_and_the_connection_stops(): void
    {
        $commerce = Plan::where('name', 'Commerce')->sole();
        $shop = Shop::create(['name' => 'Big', 'plan_id' => $commerce->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
        app(Entitlements::class)->syncFeatureFlags($shop);
        $this->assertTrue($shop->fresh()->online_store_enabled);

        $created = $this->connect($shop->fresh());
        $this->api($created['token'], 'inventory')->assertOk();

        $shop->update(['plan_id' => Plan::where('name', 'Starter')->sole()->id]);
        app(Entitlements::class)->syncFeatureFlags($shop->fresh());

        $this->assertFalse($shop->fresh()->online_store_enabled);
        $this->api($created['token'], 'inventory')->assertStatus(403)->assertJsonPath('error.code', 'connection_paused');
    }

    // ── The owner's screen ─────────────────────────────────────────────

    protected function owner(Shop $shop): User
    {
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_section_only_shows_for_a_shop_with_the_feature(): void
    {
        $shop = $this->storeShop();
        $this->owner($shop);

        Livewire::test('settings.integrations')->assertSet('section', 'online-store')->assertSee('Connect a store');
        $this->get(route('settings.index', ['tab' => 'integrations']))->assertOk()->assertSee('Connect a store');

        $off = Shop::create(['name' => 'Off', 'social_enabled' => true]);
        $this->owner($off);

        Livewire::test('settings.integrations')->assertDontSee('Online Store')->call('setSection', 'online-store')->assertSet('section', 'facebook');
        Livewire::test('settings.online-store')->assertForbidden();
    }

    public function test_the_section_needs_the_integrations_permission(): void
    {
        $shop = $this->storeShop();
        $this->actingAs(User::factory()->staff()->create(['shop_id' => $shop->id]));

        Livewire::test('settings.online-store')->assertForbidden();
    }

    public function test_connecting_shows_the_credentials_once_and_they_work(): void
    {
        $shop = $this->storeShop();
        $this->owner($shop);

        $screen = Livewire::test('settings.online-store')
            ->assertSee('No store connected yet.')
            ->call('createConnection')
            ->assertHasErrors('newName')
            ->set('newName', 'myshop.pk')
            ->call('createConnection')
            ->assertHasNoErrors();

        $token = $screen->get('shownToken');
        $this->assertStringStartsWith('hok_', $token);
        $screen->assertSee($token)->assertSee($screen->get('shownSecret'));

        $screen->call('hideCredentials')->assertDontSee($token);
        Livewire::test('settings.online-store')->assertDontSee($token)->assertSee(substr($token, 0, 10));

        $this->app['auth']->forgetGuards();
        $this->api($token, 'me')->assertOk()->assertJsonPath('data.shop_name', 'Ali Mobiles');
    }

    public function test_the_screen_publishes_and_lists_only_what_can_be_sold_online(): void
    {
        $shop = $this->storeShop();
        $created = $this->connect($shop);
        $sellable = $this->item($shop, ['name' => 'Sellable']);
        $this->item($shop, ['name' => 'By the kilo', 'unit' => 'kg']);
        $this->owner($shop);

        Livewire::test('settings.online-store')
            ->assertSee('Sellable')
            ->assertDontSee('By the kilo')
            ->assertSee('1 products aren')
            ->call('togglePublished', $sellable->id);

        $this->assertTrue(StoreProduct::sole()->published);

        Livewire::test('settings.online-store')->call('togglePublished', $sellable->id);
        $this->assertFalse(StoreProduct::sole()->published);

        Livewire::test('settings.online-store')->call('publishAll');
        $this->assertTrue(StoreProduct::sole()->published);

        Livewire::test('settings.online-store')->call('saveSku', $sellable->id, 'ABC');
        $this->assertSame('ABC', StoreProduct::sole()->external_sku);
        $this->assertSame($created['connection']->id, StoreProduct::sole()->store_connection_id);
    }

    public function test_the_screen_saves_settings_and_pauses_and_removes(): void
    {
        $shop = $this->storeShop();
        $created = $this->connect($shop);
        $this->owner($shop);

        Livewire::test('settings.online-store')
            ->set('safetyBuffer', -1)->call('saveSettings')->assertHasErrors('safetyBuffer')
            ->set('safetyBuffer', 3)->set('priceSync', true)->call('saveSettings')->assertHasNoErrors()
            ->call('pause')->assertSee('Paused')
            ->call('resume')->assertSee('Active');

        $fresh = $created['connection']->fresh();
        $this->assertSame(3, $fresh->safety_buffer);
        $this->assertTrue($fresh->price_sync);

        $screen = Livewire::test('settings.online-store')->call('newToken');
        $this->assertNotNull($screen->get('shownToken'));

        $screen->call('removeConnection')->assertSee('No store connected yet.');
        $this->assertNotNull($created['connection']->fresh()->removed_at);
    }

    public function test_one_shops_owner_cant_touch_another_shops_connection(): void
    {
        $theirs = $this->connect($this->storeShop('Theirs'));
        $this->owner($this->storeShop('Mine'));

        Livewire::test('settings.online-store')
            ->call('select', $theirs['connection']->id)
            ->assertSet('selectedId', null)
            ->assertDontSee($theirs['connection']->public_id);
    }
}
