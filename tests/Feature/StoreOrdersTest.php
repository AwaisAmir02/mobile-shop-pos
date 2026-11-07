<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\StoreConnections;
use App\Actions\StoreOrders;
use App\Enums\FulfillmentStatus;
use App\Enums\LimitKey;
use App\Enums\SaleSource;
use App\Enums\StoreOrderStatus;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\StoreConnection;
use App\Models\StoreOrder;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class StoreOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected StoreConnection $connection;

    protected string $token;

    protected string $secret;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = $this->storeShop('Ali Mobiles');
        $created = StoreConnections::create($this->shop->id, 'myshop.pk', null);
        $this->connection = $created['connection'];
        $this->token = $created['token'];
        $this->secret = $created['secret'];
        $this->product = $this->item($this->shop);
        $this->batch($this->product, 5);
        StoreConnections::publish($this->connection, collect([$this->product->fresh()]));
        StoreConnections::setExternalSku($this->connection, $this->product, 'CHG-1');
        $this->product = $this->product->fresh();
    }

    protected function storeShop(string $name, array $attributes = []): Shop
    {
        $shop = Shop::create(['name' => $name, 'online_store_enabled' => true] + $attributes);
        MainCategory::ensureDefaultsExist($shop->id);
        MainCategory::create(['shop_id' => $shop->id, 'name' => 'Goods', 'slug' => 'goods']);

        return $shop;
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

    protected function orderData(array $overrides = []): array
    {
        // Items are replaced whole, or a default product id would survive into an override.
        return array_replace(array_replace_recursive([
            'external_order_id' => '1001',
            'placed_at' => now()->toIso8601String(),
            'customer' => ['name' => 'Sara Khan', 'phone' => '+923001234567', 'address' => 'House 12', 'area' => 'Gulberg'],
            'payment' => ['method' => 'prepaid', 'amount_paid' => '2500.00'],
            'shipping_amount' => '0.00',
            'discount_amount' => '0.00',
            'items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 2, 'unit_price' => '1250.00']],
        ], $overrides), array_intersect_key($overrides, ['items' => true]));
    }

    protected function send(array $event, ?string $secret = null, ?int $timestamp = null, ?string $publicId = null, string $eventId = 'evt_1', array $headers = []): TestResponse
    {
        $body = json_encode($event);
        $timestamp ??= now()->timestamp;

        return $this->call('POST', '/webhooks/channels/'.($publicId ?? $this->connection->public_id), [], [], [], $this->transformHeadersToServerVars($headers + [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-HOK-Event-Id' => $eventId,
            'X-HOK-Timestamp' => (string) $timestamp,
            'X-HOK-Signature' => 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? $this->secret),
        ]), $body);
    }

    protected function place(array $overrides = []): StoreOrder
    {
        $this->send(['type' => 'order.created', 'data' => $this->orderData($overrides)])->assertOk();

        return StoreOrder::where('external_order_id', $overrides['external_order_id'] ?? '1001')->sole();
    }

    protected function available(): int
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/channel/v1/inventory')->json('data.0.available');
    }

    protected function owner(?Shop $shop = null): User
    {
        $user = User::factory()->create(['shop_id' => ($shop ?? $this->shop)->id, 'is_owner' => true]);
        $this->actingAs($user);

        return $user;
    }

    protected function accept(StoreOrder $order, float $paid = 2500.0, ?int $customerId = null, array $removed = []): Sale
    {
        return StoreOrders::accept($order, $removed, (float) $order->discount_amount, $customerId, $paid, User::factory()->create(['shop_id' => $this->shop->id])->id);
    }

    // ── Signatures ─────────────────────────────────────────────────────

    public function test_a_good_signature_stores_the_order(): void
    {
        $this->send(['type' => 'order.created', 'data' => $this->orderData()])
            ->assertOk()
            ->assertJsonPath('data.external_order_id', '1001')
            ->assertJsonPath('data.status', 'pending');

        $order = StoreOrder::sole();
        $this->assertSame($this->shop->id, $order->shop_id);
        $this->assertSame('Sara Khan', $order->customer_name);
        $this->assertSame('prepaid', $order->payment_method);
        $this->assertSame('evt_1', $order->event_id);
        $this->assertSame($this->product->id, $order->items->sole()->product_id);
        $this->assertSame(2, $order->items->sole()->quantity);
    }

    public function test_bad_signatures_are_refused_and_store_nothing(): void
    {
        $event = ['type' => 'order.created', 'data' => $this->orderData()];

        $this->send($event, 'wrong-secret')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->send($event, null, now()->subMinutes(6)->timestamp)->assertStatus(401);
        $this->send($event, null, now()->addMinutes(6)->timestamp)->assertStatus(401);
        $this->send($event, null, null, 'chn_doesnotexist')->assertStatus(401)->assertJsonPath('error.code', 'invalid_signature');
        $this->send($event, null, null, null, 'evt_1', ['X-HOK-Signature' => 'v1=abc'])->assertStatus(401);
        $this->send($event, null, null, null, 'evt_1', ['X-HOK-Timestamp' => 'soon'])->assertStatus(401);

        $this->assertSame(0, StoreOrder::count());
    }

    public function test_a_tampered_body_fails_the_signature(): void
    {
        $timestamp = now()->timestamp;
        $signature = 'v1='.hash_hmac('sha256', $timestamp.'.'.json_encode(['type' => 'order.created', 'data' => $this->orderData()]), $this->secret);

        $this->call('POST', '/webhooks/channels/'.$this->connection->public_id, [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json', 'X-HOK-Event-Id' => 'e', 'X-HOK-Timestamp' => (string) $timestamp, 'X-HOK-Signature' => $signature,
        ]), json_encode(['type' => 'order.created', 'data' => $this->orderData(['payment' => ['amount_paid' => '1.00']])]))->assertStatus(401);
    }

    public function test_the_old_secret_works_only_until_its_overlap_ends(): void
    {
        $old = $this->secret;
        $new = StoreConnections::rotateSecret($this->connection);

        $this->send(['type' => 'order.created', 'data' => $this->orderData()], $old)->assertOk();
        $this->send(['type' => 'order.created', 'data' => $this->orderData(['external_order_id' => '2'])], $new)->assertOk();

        $this->travel(25)->hours();

        $this->send(['type' => 'order.created', 'data' => $this->orderData(['external_order_id' => '3'])], $old)->assertStatus(401);
        $this->send(['type' => 'order.created', 'data' => $this->orderData(['external_order_id' => '4'])], $new)->assertOk();
    }

    public function test_a_removed_connection_refuses_events(): void
    {
        StoreConnections::remove($this->connection);

        $this->send(['type' => 'order.created', 'data' => $this->orderData()])->assertStatus(401);
    }

    public function test_every_store_route_names_its_shop_scoping_middleware(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(fn ($route) => $route->getName() === 'store-webhook.receive');

        $this->assertContains('store.signature', $route->gatherMiddleware());
        $this->assertNotContains('web', $route->gatherMiddleware());
    }

    // ── Paused ─────────────────────────────────────────────────────────

    public function test_a_paused_connection_refuses_a_new_order_but_takes_a_cancellation(): void
    {
        $order = $this->place();

        StoreConnections::pause($this->connection);

        $this->send(['type' => 'order.created', 'data' => $this->orderData(['external_order_id' => '2'])])->assertStatus(403)->assertJsonPath('error.code', 'connection_paused');
        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => '1001']])->assertOk();

        $this->assertSame(1, StoreOrder::count());
        $this->assertSame(StoreOrderStatus::Cancelled, $order->fresh()->status);
    }

    // ── Validation ─────────────────────────────────────────────────────

    public function test_invalid_events_get_a_validation_error_and_store_nothing(): void
    {
        foreach ([
            ['type' => 'order.exploded', 'data' => $this->orderData()],
            ['type' => 'order.created'],
            ['type' => 'order.created', 'data' => $this->orderData(['payment' => ['method' => 'barter']])],
            ['type' => 'order.created', 'data' => array_merge($this->orderData(), ['items' => []])],
            ['type' => 'order.created', 'data' => $this->orderData(['items' => [['product_id' => 1, 'name' => 'X', 'unit_price' => '1', 'quantity' => 1.5]]])],
            ['type' => 'order.created', 'data' => $this->orderData(['items' => [['product_id' => 1, 'name' => 'X', 'unit_price' => '1', 'quantity' => 0]]])],
            ['type' => 'order.created', 'data' => array_merge($this->orderData(), ['items' => [['name' => 'No id', 'quantity' => 1, 'unit_price' => '1']]])],
            ['type' => 'order.created', 'data' => $this->orderData(['payment' => ['method' => 'cod'], 'customer' => ['phone' => null]])],
        ] as $event) {
            $this->send($event)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed');
        }

        $this->send(['type' => 'order.created', 'data' => $this->orderData()], null, null, null, '')->assertStatus(422);

        $this->assertSame(0, StoreOrder::count());
    }

    public function test_an_oversized_request_is_refused(): void
    {
        $this->send(['type' => 'order.created', 'data' => $this->orderData(['note' => str_repeat('x', 300000)])])->assertStatus(422);
    }

    // ── Duplicates and matching ────────────────────────────────────────

    public function test_sending_the_same_order_twice_changes_nothing(): void
    {
        $first = $this->place();

        $this->send(['type' => 'order.created', 'data' => $this->orderData(['customer' => ['name' => 'Someone Else']])], null, null, null, 'evt_2')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, StoreOrder::count());
        $this->assertSame('Sara Khan', $first->fresh()->customer_name);
        $this->assertSame(3, $this->available());
    }

    public function test_the_same_order_id_on_two_connections_is_two_orders(): void
    {
        $other = StoreConnections::create($this->shop->id, 'second.pk', null);

        $this->place();
        $this->send(['type' => 'order.created', 'data' => $this->orderData()], $other['secret'], null, $other['connection']->public_id)->assertOk();

        $this->assertSame(2, StoreOrder::count());
    }

    public function test_items_match_by_product_or_by_the_stores_code_and_otherwise_wait_for_a_person(): void
    {
        $order = $this->place(['items' => [
            ['product_id' => $this->product->id, 'name' => 'By id', 'quantity' => 1, 'unit_price' => '100'],
            1 => ['external_sku' => 'CHG-1', 'name' => 'By code', 'quantity' => 1, 'unit_price' => '100'],
        ]]);

        $this->assertSame([$this->product->id, $this->product->id], $order->items->pluck('product_id')->all());

        $unknown = $this->place(['external_order_id' => '2', 'items' => [['product_id' => 999999, 'external_sku' => 'NOPE', 'name' => 'Mystery', 'quantity' => 1, 'unit_price' => '100']]]);

        $this->assertNull($unknown->items->sole()->product_id);
        $this->assertSame('Mystery', $unknown->items->sole()->product_name);
    }

    public function test_a_product_in_the_shop_but_not_on_this_connection_doesnt_match(): void
    {
        $unlisted = $this->item($this->shop, ['name' => 'Unlisted']);

        $order = $this->place(['items' => [['product_id' => $unlisted->id, 'name' => 'Unlisted', 'quantity' => 1, 'unit_price' => '1']]]);

        $this->assertNull($order->items->sole()->product_id);
    }

    public function test_another_shops_product_never_matches(): void
    {
        $theirs = $this->item($this->storeShop('Other'), ['name' => 'Theirs']);

        $order = $this->place(['items' => [['product_id' => $theirs->id, 'name' => 'Theirs', 'quantity' => 1, 'unit_price' => '1']]]);

        $this->assertNull($order->items->sole()->product_id);
        $this->assertSame($this->shop->id, $order->shop_id);
    }

    // ── Holding stock ──────────────────────────────────────────────────

    public function test_a_waiting_order_only_holds_stock_back_from_the_published_figure(): void
    {
        $this->assertSame(5, $this->available());

        $order = $this->place();

        $this->assertSame(3, $this->available());
        $this->assertSame(5.0, (float) Quantity::normalize($this->product->fresh()->stock_quantity));
        $this->assertSame(5.0, (float) StockIn::sole()->quantity_remaining);
        $this->assertSame(0, Sale::count());

        StoreOrders::reject($order, 'No', User::factory()->create(['shop_id' => $this->shop->id])->id);

        $this->assertSame(5, $this->available());
    }

    public function test_a_second_connection_is_offered_less_while_the_first_has_a_waiting_order(): void
    {
        $second = StoreConnections::create($this->shop->id, 'second.pk', null);
        StoreConnections::publish($second['connection'], collect([$this->product->fresh()]));

        $this->place();

        $this->withHeader('Authorization', 'Bearer '.$second['token'])->getJson('/api/channel/v1/inventory')->assertJsonPath('data.0.available', 3);
    }

    public function test_a_waiting_order_arriving_shows_in_inventory_changes(): void
    {
        $this->travel(1)->hours();
        $since = urlencode(now()->toIso8601String());
        $this->travel(1)->minutes();

        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson("/api/channel/v1/inventory?updated_since={$since}")->assertJsonCount(0, 'data');

        $this->place();

        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson("/api/channel/v1/inventory?updated_since={$since}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.available', 3);
    }

    // ── Accepting: the same path as any sale ───────────────────────────

    public function test_accepting_makes_an_ordinary_sale_through_fifo(): void
    {
        $this->batch($this->product, 5, ['cost_price' => 400, 'selling_price' => 600, 'total_cost' => 2000, 'stock_date' => now()->addDay()->toDateString()]);

        $order = $this->place(['payment' => ['amount_paid' => '7500.00'], 'items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 6, 'unit_price' => '1250.00']]]);
        $sale = $this->accept($order, 7500.0);

        $this->assertSame(SaleSource::Online, $sale->fresh()->source);
        $this->assertSame(StoreOrderStatus::Accepted, $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame(7500.0, (float) $sale->total);

        // Oldest batch first, each sub-line keeping its batch's frozen cost, charged what the store charged.
        $this->assertSame(
            [[5.0, 300.0, 1250.0, 550.0], [1.0, 400.0, 1250.0, 600.0]],
            SaleItem::where('sale_id', $sale->id)->orderBy('id')->get()->map(fn (SaleItem $item) => [(float) $item->quantity, (float) $item->cost_price, (float) $item->unit_price, (float) $item->list_price])->all()
        );
        $this->assertSame(4.0, (float) Quantity::normalize($this->product->fresh()->stock_quantity));
        $this->assertSame([0.0, 4.0], StockIn::orderBy('id')->get()->map(fn (StockIn $batch) => (float) $batch->quantity_remaining)->all());
        $this->assertSame(4, $this->available());
    }

    public function test_an_order_for_more_than_is_left_is_refused_and_moves_nothing(): void
    {
        $order = $this->place(['items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 9, 'unit_price' => '100']]]);

        $this->assertSame(0, $this->available());

        try {
            $this->accept($order, 900.0);
            $this->fail('It should have been refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('left in stock', json_encode($e->errors()));
        }

        $this->assertSame(StoreOrderStatus::Pending, $order->fresh()->status);
        $this->assertSame(0, Sale::count());
        $this->assertSame(5.0, (float) StockIn::sole()->quantity_remaining);
        $this->assertSame(5.0, (float) Quantity::normalize($this->product->fresh()->stock_quantity));
    }

    public function test_expired_stock_cant_be_accepted_any_more_than_sold(): void
    {
        $shop = $this->storeShop('Pharmacy', ['expiry_tracking_enabled' => true]);
        $created = StoreConnections::create($shop->id, 'pharma.pk', null);
        $product = $this->item($shop);
        $this->batch($product, 3, ['expiry_date' => now()->subDays(3)->toDateString()]);
        StoreConnections::publish($created['connection'], collect([$product->fresh()]));

        $data = $this->orderData(['items' => [['product_id' => $product->id, 'name' => 'Old', 'quantity' => 1, 'unit_price' => '100']]]);
        $this->send(['type' => 'order.created', 'data' => $data], $created['secret'], null, $created['connection']->public_id)->assertOk();

        $this->expectException(ValidationException::class);

        StoreOrders::accept(StoreOrder::where('shop_id', $shop->id)->sole(), [], 0.0, null, 100.0, User::factory()->create(['shop_id' => $shop->id])->id);
    }

    public function test_accepting_twice_or_after_a_decision_is_refused(): void
    {
        $order = $this->place();
        $this->accept($order);

        $this->expectException(ValidationException::class);

        $this->accept($order);
    }

    public function test_paid_cant_exceed_the_sale_and_unpaid_needs_a_customer(): void
    {
        $order = $this->place();

        foreach ([[9999.0, null], [100.0, null]] as [$paid, $customer]) {
            try {
                $this->accept($order, $paid, $customer);
                $this->fail('It should have been refused.');
            } catch (ValidationException) {
                $this->assertSame(StoreOrderStatus::Pending, $order->fresh()->status);
                $this->assertSame(0, Sale::count());
                $this->assertSame(5.0, (float) StockIn::sole()->quantity_remaining);
            }
        }
    }

    public function test_cod_becomes_a_normal_customer_due(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Sara', 'phone' => '+923001234567']);
        $order = $this->place(['payment' => ['method' => 'cod', 'amount_paid' => null]]);

        $sale = $this->accept($order, 0.0, $customer->id);

        $this->assertSame($customer->id, $sale->customer_id);
        $this->assertSame(0.0, (float) $sale->payments()->sum('amount'));
        $this->assertSame(2500.0, (float) $sale->total);
    }

    public function test_a_customer_from_another_shop_is_refused(): void
    {
        $theirs = Customer::create(['shop_id' => $this->storeShop('Other')->id, 'name' => 'Theirs']);
        $order = $this->place();

        $this->expectException(ValidationException::class);

        $this->accept($order, 0.0, $theirs->id);
    }

    public function test_a_line_with_no_product_blocks_acceptance_until_mapped_or_removed(): void
    {
        $order = $this->place(['items' => [
            ['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 1, 'unit_price' => '1000'],
            1 => ['external_sku' => 'MYSTERY', 'name' => 'Mystery', 'quantity' => 1, 'unit_price' => '500'],
        ]]);
        $mystery = $order->items->firstWhere('external_sku', 'MYSTERY');

        try {
            $this->accept($order, 1500.0);
            $this->fail('An unmatched line should block it.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Mystery', json_encode($e->errors()));
        }

        $sale = $this->accept($order, 1000.0, null, [$mystery->id]);

        $this->assertSame(1000.0, (float) $sale->total);
        $this->assertTrue($mystery->fresh()->removed);
    }

    public function test_mapping_a_line_remembers_the_stores_code_for_next_time(): void
    {
        $other = $this->item($this->shop, ['name' => 'Cable']);
        $order = $this->place(['items' => [['external_sku' => 'CBL-9', 'name' => 'Cable', 'quantity' => 1, 'unit_price' => '100']]]);

        StoreOrders::mapItem($order->items->sole(), $other);

        $this->assertSame($other->id, $order->fresh()->items->sole()->product_id);

        $next = $this->place(['external_order_id' => '2', 'items' => [['external_sku' => 'CBL-9', 'name' => 'Cable', 'quantity' => 1, 'unit_price' => '100']]]);

        $this->assertSame($other->id, $next->items->sole()->product_id);
    }

    public function test_a_line_cant_be_mapped_to_a_product_that_cant_be_sold_online(): void
    {
        $order = $this->place(['items' => [['external_sku' => 'X', 'name' => 'X', 'quantity' => 1, 'unit_price' => '100']]]);

        foreach ([$this->item($this->shop, ['name' => 'Kilo', 'unit' => 'kg']), $this->item($this->storeShop('Other'))] as $product) {
            try {
                StoreOrders::mapItem($order->items->sole(), $product);
                $this->fail('It should have been refused.');
            } catch (ValidationException) {
                $this->assertNull($order->items->sole()->fresh()->product_id);
            }
        }
    }

    // ── Cancelling ─────────────────────────────────────────────────────

    public function test_a_store_cancel_closes_a_waiting_order_and_frees_its_stock(): void
    {
        $order = $this->place();

        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => '1001', 'reason' => 'Changed mind']])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(StoreOrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(5, $this->available());
    }

    public function test_a_store_cancel_after_acceptance_only_raises_a_flag(): void
    {
        $order = $this->place();
        $sale = $this->accept($order);

        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => '1001', 'reason' => 'Changed mind']])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        $fresh = $order->fresh();
        $this->assertNotNull($fresh->cancel_flagged_at);
        $this->assertSame('Changed mind', $fresh->cancel_reason);
        $this->assertSame(StoreOrderStatus::Accepted, $fresh->status);
        $this->assertNotSame('cancelled', $sale->fresh()->status->value);
        $this->assertSame(3.0, (float) Quantity::normalize($this->product->fresh()->stock_quantity));

        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/channel/v1/orders/1001')->assertJsonPath('data.cancel_flagged', true);
    }

    public function test_cancelling_an_unknown_order_is_not_found_and_repeats_change_nothing(): void
    {
        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => 'nope']])->assertStatus(404);

        $order = $this->place();
        StoreOrders::reject($order, 'No', User::factory()->create(['shop_id' => $this->shop->id])->id);

        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => '1001']])->assertOk()->assertJsonPath('data.status', 'rejected');
    }

    public function test_a_person_cancelling_the_sale_returns_the_stock_to_its_batch(): void
    {
        $order = $this->place();
        $sale = $this->accept($order);

        $this->assertSame(3.0, (float) StockIn::sole()->quantity_remaining);

        $person = $this->owner();
        CancelSale::handle($sale, $person->id, null);

        $this->assertSame(5.0, (float) StockIn::sole()->quantity_remaining);
        $this->assertSame(5, $this->available());
    }

    // ── Reading an order back ──────────────────────────────────────────

    public function test_an_order_reads_back_with_its_status_and_only_on_its_own_connection(): void
    {
        $order = $this->place();

        $read = fn (string $token, string $id = '1001') => $this->withHeader('Authorization', 'Bearer '.$token)->getJson("/api/channel/v1/orders/{$id}");

        $read($this->token)->assertOk()
            ->assertJsonPath('data.external_order_id', '1001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.fulfillment_status', null)
            ->assertJsonPath('data.cancel_flagged', false);

        StoreOrders::reject($order, 'Out of stock', User::factory()->create(['shop_id' => $this->shop->id])->id);
        $read($this->token)->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.reject_reason', 'Out of stock');

        $read($this->token, 'nope')->assertStatus(404)->assertJsonPath('error.code', 'not_found');

        // Same shop's other connection, and another shop altogether.
        $read(StoreConnections::create($this->shop->id, 'second.pk', null)['token'])->assertStatus(404);
        $read(StoreConnections::create($this->storeShop('Other')->id, 'third.pk', null)['token'])->assertStatus(404);
    }

    // ── Fulfilment ─────────────────────────────────────────────────────

    public function test_fulfilment_only_moves_forward_and_only_on_accepted_orders(): void
    {
        $order = $this->place();

        try {
            StoreOrders::updateFulfillment($order, FulfillmentStatus::Packed);
            $this->fail('A waiting order has nothing to fulfil.');
        } catch (ValidationException) {
        }

        $this->accept($order);
        StoreOrders::updateFulfillment($order, FulfillmentStatus::OutForDelivery);

        $this->withHeader('Authorization', 'Bearer '.$this->token)->getJson('/api/channel/v1/orders/1001')->assertJsonPath('data.fulfillment_status', 'out_for_delivery');

        $this->expectException(ValidationException::class);

        StoreOrders::updateFulfillment($order, FulfillmentStatus::Packed);
    }

    // ── Limits and plans ───────────────────────────────────────────────

    protected function limitOrders(int $limit): void
    {
        $plan = Plan::create(['name' => 'Limited', 'price' => 1, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => ['online_orders_per_month' => $limit], 'feature_flags' => ['online_store' => true]]);
        $this->shop->update(['plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
    }

    public function test_over_the_limit_orders_are_stored_but_cant_be_accepted(): void
    {
        $this->limitOrders(1);

        $first = $this->place();
        $this->accept($first);

        $second = $this->place(['external_order_id' => '2', 'items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 1, 'unit_price' => '100']]]);

        $this->assertSame(StoreOrderStatus::Pending, $second->status);
        $this->assertSame(1, app(Entitlements::class)->usage($this->shop->fresh(), LimitKey::OnlineOrdersPerMonth));

        try {
            $this->accept($second, 100.0);
            $this->fail('Over the limit should be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reached this month', json_encode($e->errors()));
        }

        $this->assertSame(StoreOrderStatus::Pending, $second->fresh()->status);

        // A new month has room.
        $this->travel(35)->days();
        $this->accept($second, 100.0);
        $this->assertSame(StoreOrderStatus::Accepted, $second->fresh()->status);
    }

    public function test_only_accepted_orders_count_towards_the_limit(): void
    {
        $this->limitOrders(1);

        $rejected = $this->place();
        StoreOrders::reject($rejected, 'No', User::factory()->create(['shop_id' => $this->shop->id])->id);
        $this->place(['external_order_id' => '2']);

        $this->assertSame(0, app(Entitlements::class)->usage($this->shop->fresh(), LimitKey::OnlineOrdersPerMonth));
    }

    public function test_after_the_feature_goes_orders_stay_readable_but_cant_be_accepted(): void
    {
        $order = $this->place();
        $this->shop->update(['online_store_enabled' => false]);
        $this->owner();

        $this->get(route('online-orders.index'))->assertOk()->assertSee($order->orderNumber());

        $this->expectException(ValidationException::class);

        $this->accept($order);
    }

    // ── Sales reports ──────────────────────────────────────────────────

    public function test_online_sales_show_in_sales_by_source(): void
    {
        $this->owner();
        $this->accept($this->place());

        $rows = (new \App\Services\Marketing\MarketingStats(now()->subDay(), now()->addDay()))->salesBySource();

        $this->assertSame(1, $rows['online']['orders']);
        $this->assertSame(2500.0, $rows['online']['revenue']);
        $this->assertSame('Online store', $rows['online']['label']);
    }

    // ── The screens ────────────────────────────────────────────────────

    public function test_the_inbox_lists_by_status_and_is_scoped_to_the_shop(): void
    {
        $order = $this->place();
        $this->owner();

        $this->get(route('online-orders.index'))->assertOk()->assertSee($order->orderNumber())->assertSee('Sara Khan')->assertSee('Rs 2,500.00');

        Livewire::test('online-orders.index')->call('setTab', 'accepted')->assertDontSee($order->orderNumber());
        Livewire::test('online-orders.index')->call('setTab', 'nonsense')->assertSet('tab', 'pending');

        $this->owner($this->storeShop('Other'));
        $this->get(route('online-orders.index'))->assertOk()->assertDontSee($order->orderNumber());
        $this->get(route('online-orders.show', $order))->assertNotFound();
    }

    public function test_the_sidebar_shows_the_inbox_only_with_the_feature(): void
    {
        $this->owner();
        $this->get(route('dashboard'))->assertSee('Online Store Orders');

        $this->owner(Shop::create(['name' => 'Off']));
        $this->get(route('dashboard'))->assertDontSee('Online Store Orders');
    }

    public function test_accepting_on_the_screen_suggests_the_goods_total_and_leaves_delivery_out(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        StoreConnections::updateSettings($this->connection, false, 0, $account->id);
        $order = $this->place(['payment' => ['amount_paid' => '2700.00'], 'shipping_amount' => '200.00']);
        $this->owner();

        $screen = Livewire::test('online-orders.show', ['order' => $order])
            ->assertSet('amountPaid', '2500.00')
            ->assertSet('paymentMethod', (string) $account->id)
            ->assertSee('The sale records goods only')
            ->assertSee('Rs 200.00')
            ->call('accept')
            ->assertHasNoErrors();

        $sale = Sale::sole();
        $screen->assertRedirect(route('sales.show', $sale));

        $this->assertSame(2500.0, (float) $sale->total);
        $this->assertSame(2500.0, (float) $sale->payments()->sum('amount'));
        $this->assertSame($account->id, $sale->payments()->sole()->shop_account_id);
    }

    public function test_cod_on_the_screen_starts_unpaid_and_needs_a_customer_which_it_can_make(): void
    {
        $order = $this->place(['payment' => ['method' => 'cod']]);
        $this->owner();

        $screen = Livewire::test('online-orders.show', ['order' => $order])
            ->assertSet('amountPaid', '0')
            ->assertSet('customerId', '')
            ->call('accept')
            ->assertHasErrors('customerId');

        $this->assertSame(0, Sale::count());

        $screen->call('createCustomerFromOrder')->call('accept')->assertHasNoErrors();

        $customer = Customer::where('name', 'Sara Khan')->sole();
        $this->assertSame('+923001234567', $customer->phone);
        $this->assertSame('House 12, Gulberg', $customer->address);
        $this->assertSame($customer->id, Sale::sole()->customer_id);
    }

    public function test_a_single_customer_with_the_orders_phone_is_suggested(): void
    {
        $customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Sara', 'phone' => '+923001234567']);
        $order = $this->place();
        $this->owner();

        Livewire::test('online-orders.show', ['order' => $order])->assertSet('customerId', (string) $customer->id);

        Customer::create(['shop_id' => $this->shop->id, 'name' => 'Sara Two', 'phone' => '+923001234567']);

        Livewire::test('online-orders.show', ['order' => $order])->assertSet('customerId', '');
    }

    public function test_the_screen_maps_and_removes_lines_and_shows_refusals(): void
    {
        $other = $this->item($this->shop, ['name' => 'Cable']);
        $order = $this->place(['items' => [
            ['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 1, 'unit_price' => '1000'],
            1 => ['external_sku' => 'CBL', 'name' => 'Cable from store', 'quantity' => 1, 'unit_price' => '500'],
        ]]);
        $line = $order->items->firstWhere('external_sku', 'CBL');
        $this->owner();

        $screen = Livewire::test('online-orders.show', ['order' => $order])
            ->assertSee('not matched to a product')
            ->assertSet('amountPaid', '1500.00')
            ->call('accept')
            ->assertHasErrors('lines');

        $screen->call('toggleLine', $line->id)->assertSet('amountPaid', '1000.00')->call('toggleLine', $line->id);

        $screen->call('mapLine', $line->id, (string) $other->id)->assertDontSee('not matched to a product');
        $this->assertSame($other->id, $line->fresh()->product_id);
    }

    public function test_short_stock_shows_on_the_screen_without_moving_anything(): void
    {
        $order = $this->place(['payment' => ['amount_paid' => '900'], 'items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 9, 'unit_price' => '100']]]);
        $this->owner();

        Livewire::test('online-orders.show', ['order' => $order])->call('accept')->assertHasErrors('lines');

        $this->assertSame(StoreOrderStatus::Pending, $order->fresh()->status);
    }

    public function test_rejecting_on_the_screen_needs_a_reason(): void
    {
        $order = $this->place();
        $this->owner();

        Livewire::test('online-orders.show', ['order' => $order])
            ->call('reject')->assertHasErrors('rejectReason')
            ->set('rejectReason', 'Out of stock')->call('reject')->assertHasNoErrors();

        $this->assertSame(StoreOrderStatus::Rejected, $order->fresh()->status);
        $this->assertSame('Out of stock', $order->fresh()->reject_reason);
    }

    public function test_viewing_doesnt_allow_deciding(): void
    {
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Viewer', 'permissions' => ['online-orders']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $order = $this->place();

        $this->get(route('online-orders.show', $order))->assertOk()->assertDontSee('Accept &amp; create sale', false);

        Livewire::test('online-orders.show', ['order' => $order])->call('accept')->assertForbidden();
        Livewire::test('online-orders.show', ['order' => $order])->set('rejectReason', 'x')->call('reject')->assertForbidden();
        Livewire::test('online-orders.show', ['order' => $order])->call('createCustomerFromOrder')->assertForbidden();
    }

    public function test_the_screens_need_the_permission(): void
    {
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Other', 'permissions' => ['products']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $order = $this->place();

        $this->get(route('online-orders.index'))->assertForbidden();
        $this->get(route('online-orders.show', $order))->assertForbidden();
        Livewire::test('online-orders.index')->assertForbidden();
    }

    public function test_a_store_cancel_request_shows_and_can_be_dealt_with(): void
    {
        $order = $this->place();
        $this->accept($order);
        $this->send(['type' => 'order.cancelled', 'data' => ['external_order_id' => '1001', 'reason' => 'Changed mind']])->assertOk();
        $this->owner();

        $this->get(route('online-orders.index', ['tab' => 'accepted']))->assertSee('Cancel requested');

        Livewire::test('online-orders.show', ['order' => $order->fresh()])
            ->assertSee('The store asked to cancel this order: Changed mind')
            ->call('clearCancelRequest')
            ->assertDontSee('The store asked to cancel');
    }
}
