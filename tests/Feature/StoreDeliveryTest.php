<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CancelStockIn;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\CreateStockWriteOff;
use App\Actions\StoreConnections;
use App\Actions\StoreOrders;
use App\Actions\UpdateSale;
use App\Enums\FulfillmentStatus;
use App\Enums\WhatsAppOrderStatus;
use App\Enums\WriteOffReason;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StoreConnection;
use App\Models\StoreEvent;
use App\Models\StoreOrder;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\Models\WhatsAppOrderItem;
use App\Support\StoreCallbackGuard;
use App\Support\StoreChanges;
use App\Support\StoreDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected const URL = 'https://myshop.pk/events';

    protected Shop $shop;

    protected StoreConnection $connection;

    protected string $secret;

    protected Product $product;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // No real DNS in tests: everything resolves to a public address unless a test says otherwise.
        $this->app->instance(StoreCallbackGuard::class, new class extends StoreCallbackGuard
        {
            public array $answers = [];

            public function resolve(string $host): array
            {
                return $this->answers[$host] ?? ['93.184.216.34'];
            }
        });

        $this->shop = $this->storeShop('Ali Mobiles');
        $this->user = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $created = StoreConnections::create($this->shop->id, 'myshop.pk', null);
        $this->connection = $created['connection'];
        $this->secret = $created['secret'];
        $this->product = $this->item($this->shop);
        $this->batch($this->product, 5);
        StoreConnections::publish($this->connection, collect([$this->product->fresh()]));
        StoreConnections::setCallbackUrl($this->connection, self::URL);
        $this->connection = $this->connection->fresh();
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

    protected function sell(int $quantity = 1): \App\Models\Sale
    {
        return CreateSale::handle([['product_id' => $this->product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->user->id, now()->toDateString(), 550.0 * $quantity, $this->shop->id);
    }

    /** Runs the push with every store answering OK, and gives back what was sent. */
    protected function pushed(): array
    {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);
        app(StoreDelivery::class)->run();

        return collect(Http::recorded())->map(fn ($pair) => json_decode($pair[0]->body(), true))->all();
    }

    protected function changes(string $kind = StoreChanges::STOCK): array
    {
        return DB::table('store_changes')->where('kind', $kind)->orderBy('product_id')->pluck('product_id')->all();
    }

    protected function clearOutbox(): void
    {
        DB::table('store_changes')->delete();
        StoreEvent::withoutGlobalScopes()->delete();
    }

    protected function orderData(array $overrides = []): array
    {
        return $overrides + [
            'external_order_id' => '1001',
            'placed_at' => now()->toIso8601String(),
            'customer' => ['name' => 'Sara', 'phone' => '+923001234567'],
            'payment' => ['method' => 'prepaid', 'amount_paid' => 500],
            'items' => [['product_id' => $this->product->id, 'name' => 'Charger', 'quantity' => 1, 'unit_price' => 500]],
        ];
    }

    // ── A shop with no store to tell ───────────────────────────────────

    public function test_a_shop_with_no_callback_writes_nothing_when_stock_moves(): void
    {
        $shop = $this->storeShop('Quiet');
        $created = StoreConnections::create($shop->id, 'quiet.pk', null);
        $product = $this->item($shop);
        $this->batch($product, 3);
        StoreConnections::publish($created['connection'], collect([$product->fresh()]));
        $this->clearOutbox();

        CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, $this->user->id, now()->toDateString(), 550.0, $shop->id);

        $this->assertSame(0, DB::table('store_changes')->count());
        $this->assertSame(0, StoreEvent::withoutGlobalScopes()->count());
    }

    public function test_a_shop_with_no_connection_writes_nothing(): void
    {
        $shop = Shop::create(['name' => 'Plain']);
        $product = $this->item($shop);
        $this->clearOutbox();

        $product->increment('stock_quantity', 5);
        $product->update(['name' => 'Renamed']);

        $this->assertSame(0, DB::table('store_changes')->count());
    }

    public function test_an_unpublished_product_isnt_noted(): void
    {
        $other = $this->item($this->shop, ['name' => 'Unlisted']);
        $this->clearOutbox();

        $other->increment('stock_quantity', 3);

        $this->assertSame([], $this->changes());
    }

    // ── Every stock writer is noticed ──────────────────────────────────

    public function test_a_sale_is_noted_once_however_much_moves(): void
    {
        $this->clearOutbox();

        $this->sell();
        $this->sell();

        $this->assertSame([$this->product->id], $this->changes());
    }

    public function test_every_writer_of_stock_is_noticed(): void
    {
        $this->actingAs($this->user);

        $check = function (string $what, callable $action) {
            $this->clearOutbox();
            $action();
            $this->assertSame([$this->product->id], $this->changes(), "{$what} wasn't noticed.");
        };

        $sale = null;
        $check('a sale', function () use (&$sale) {
            $sale = $this->sell();
        });
        $check('editing a sale', fn () => UpdateSale::handle($sale, [['product_id' => $this->product->id, 'product_name' => $this->product->name, 'product_type' => $this->product->type, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 275.0]], 0.0, null, $this->user->id, now()->toDateString()));
        $check('cancelling a sale', fn () => CancelSale::handle($sale->fresh(), $this->user->id, null));

        $entries = null;
        $check('stock in', function () use (&$entries) {
            $entries = CreateStockIn::handle([['product_id' => $this->product->id, 'quantity' => 2, 'cost_price' => 300.0, 'selling_price' => 550.0]], null, 'unpaid', null, now()->toDateString(), null, $this->user->id);
        });
        $check('cancelling stock in', fn () => CancelStockIn::handle($entries->first(), $this->user->id, null));
        $check('a write-off', fn () => CreateStockWriteOff::handle($this->product->fresh(), 1, WriteOffReason::cases()[0], 'Test', $this->user->id, now()->toDateString()));
        $check('editing a product\'s stock', fn () => $this->product->fresh()->update(['stock_quantity' => 2]));
    }

    public function test_waiting_orders_arriving_and_being_decided_are_noticed(): void
    {
        $this->clearOutbox();
        $order = StoreOrders::receive($this->connection, $this->orderData(), 'e');
        $this->assertSame([$this->product->id], $this->changes(), 'An order arriving holds stock.');

        $this->clearOutbox();
        StoreOrders::reject($order, 'No', $this->user->id);
        $this->assertSame([$this->product->id], $this->changes(), 'Rejecting frees it.');

        $this->clearOutbox();
        $cancelled = StoreOrders::receive($this->connection, $this->orderData(['external_order_id' => '2']), 'e');
        $this->clearOutbox();
        StoreOrders::cancelFromStore($cancelled, null);
        $this->assertSame([$this->product->id], $this->changes(), 'A store cancel frees it.');
    }

    public function test_a_whatsapp_order_being_placed_and_decided_is_noticed(): void
    {
        $contact = WhatsAppContact::withoutGlobalScopes()->forceCreate(['shop_id' => $this->shop->id, 'wa_id' => '923001234567']);
        $order = WhatsAppOrder::withoutGlobalScopes()->create(['shop_id' => $this->shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Collecting]);
        WhatsAppOrderItem::withoutGlobalScopes()->create(['shop_id' => $this->shop->id, 'whatsapp_order_id' => $order->id, 'product_id' => $this->product->id, 'product_name' => 'Charger', 'quantity' => 1, 'unit' => 'piece', 'quoted_unit_price' => 500]);
        $this->clearOutbox();

        $order->update(['status' => WhatsAppOrderStatus::Pending]);
        $this->assertSame([$this->product->id], $this->changes());

        $this->clearOutbox();
        $order->update(['customer_note' => 'Hello']);
        $this->assertSame([], $this->changes(), 'Only a change of status matters.');
    }

    public function test_batch_edits_are_noticed_and_details_changing_is_a_product_update(): void
    {
        $this->clearOutbox();

        StockIn::where('product_id', $this->product->id)->first()->update(['expiry_date' => now()->addDays(2)->toDateString()]);
        $this->assertSame([$this->product->id], $this->changes());

        $this->clearOutbox();
        $this->product->fresh()->update(['name' => 'New name']);
        $this->assertSame([$this->product->id], $this->changes(StoreChanges::CATALOG));

        $this->clearOutbox();
        $this->sell();
        $this->assertSame([], $this->changes(StoreChanges::CATALOG), 'A sale alone says nothing new about the product.');
    }

    public function test_with_price_sync_on_stock_moves_also_send_the_product(): void
    {
        StoreConnections::updateSettings($this->connection, true, 0);
        $this->clearOutbox();

        $this->sell();

        $this->assertSame([$this->product->id], $this->changes(StoreChanges::CATALOG));
    }

    public function test_a_groups_change_reaches_its_variants(): void
    {
        $group = ProductGroup::create(['shop_id' => $this->shop->id, 'name' => 'Charger', 'type' => 'goods']);
        $this->product->update(['product_group_id' => $group->id, 'variant_label' => 'Black']);
        $this->clearOutbox();

        $group->update(['name' => 'Fast Charger']);

        $this->assertSame([$this->product->id], $this->changes(StoreChanges::CATALOG));
    }

    // ── What gets sent ─────────────────────────────────────────────────

    public function test_the_first_push_sends_absolute_stock_signed_as_documented(): void
    {
        Http::fake(['*' => Http::response('', 200)]);

        $this->artisan('store:push')->assertSuccessful();

        Http::assertSent(function (Request $request) {
            $body = $request->body();
            $event = json_decode($body, true);
            $timestamp = $request->header('X-HOK-Timestamp')[0];

            return $request->url() === self::URL
                && $request->header('Content-Type')[0] === 'application/json'
                && $request->header('X-HOK-Event-Id')[0] === $event['id']
                && str_starts_with($event['id'], 'evt_')
                && $request->header('X-HOK-Signature')[0] === 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $this->secret)
                && $event['type'] === 'inventory.updated'
                && str_ends_with($event['created_at'], '+05:00')
                && $event['data']['items'][0]['product_id'] === $this->product->id
                && $event['data']['items'][0]['available'] === 5
                && is_int($event['data']['items'][0]['sequence']);
        });

        $this->assertNotNull(StoreEvent::sole()->delivered_at);
        $this->assertSame(200, StoreEvent::sole()->response_status);
        $this->assertSame([], $this->changes());
    }

    public function test_many_changes_become_one_event_with_the_latest_figure(): void
    {
        $this->pushed();
        Http::clearResolvedInstances();

        $this->sell();
        $this->sell();
        $this->sell();

        $sent = $this->pushed();
        $last = end($sent);

        $this->assertSame('inventory.updated', $last['type']);
        $this->assertSame(2, $last['data']['items'][0]['available']);
    }

    public function test_later_events_carry_higher_sequences(): void
    {
        $first = $this->pushed();
        usleep(2000);
        $this->sell();
        $second = $this->pushed();

        $this->assertGreaterThan($first[0]['data']['items'][0]['sequence'], end($second)['data']['items'][0]['sequence']);
    }

    public function test_a_waiting_order_lowers_the_pushed_figure(): void
    {
        $this->pushed();
        StoreOrders::receive($this->connection, $this->orderData(), 'e');

        $sent = $this->pushed();

        $this->assertSame(4, end($sent)['data']['items'][0]['available']);
    }

    public function test_product_details_and_unpublishing_are_sent(): void
    {
        $this->pushed();
        $this->product->fresh()->update(['name' => 'New name']);

        $sent = collect($this->pushed())->keyBy('type');
        $this->assertSame('New name', $sent['product.updated']['data']['name']);
        $this->assertTrue($sent['product.updated']['data']['published']);

        StoreConnections::unpublish($this->connection, $this->product->fresh());

        $sent = collect($this->pushed())->keyBy('type');
        $this->assertSame($this->product->id, $sent['product.unpublished']['data']['product_id']);
    }

    public function test_archiving_tells_the_store_to_stop_and_restocking_brings_it_back(): void
    {
        $this->pushed();

        // Sold out, then archived.
        $this->product->fresh()->update(['stock_quantity' => 0, 'archived_at' => now()]);
        $this->assertArrayHasKey('product.unpublished', collect($this->pushed())->keyBy('type')->all());

        // Stock arriving unarchives a product by itself, quietly: the store still has to hear.
        $this->clearOutbox();
        $this->product->fresh()->increment('stock_quantity', 3);
        $this->assertNull($this->product->fresh()->archived_at);

        $sent = collect($this->pushed())->keyBy('type');
        $this->assertSame(3, $sent['inventory.updated']['data']['items'][0]['available']);
        $this->assertTrue($sent['product.updated']['data']['published']);
    }

    public function test_deleting_a_product_tells_the_store(): void
    {
        $this->pushed();
        $id = $this->product->id;

        $this->product->fresh()->delete();

        $sent = collect($this->pushed())->keyBy('type');
        $this->assertSame($id, $sent['product.unpublished']['data']['product_id']);
    }

    public function test_order_decisions_are_sent(): void
    {
        $order = StoreOrders::receive($this->connection, $this->orderData(), 'e');
        StoreOrders::accept($order, [], 0.0, null, 500.0, $this->user->id);
        StoreOrders::updateFulfillment($order, FulfillmentStatus::Packed);
        StoreOrders::cancelFromStore($order, 'Changed mind');

        $rejected = StoreOrders::receive($this->connection, $this->orderData(['external_order_id' => '2']), 'e');
        StoreOrders::reject($rejected, 'Out of stock', $this->user->id);

        $sent = collect($this->pushed())->keyBy('type');

        $this->assertSame(['external_order_id' => '1001'], $sent['order.accepted']['data']);
        $this->assertSame(['external_order_id' => '1001', 'fulfillment_status' => 'packed'], $sent['order.fulfillment_changed']['data']);
        $this->assertSame(['external_order_id' => '1001'], $sent['order.cancel_flagged']['data']);
        // MySQL sorts a JSON column's keys, so order isn't part of this.
        $this->assertEquals(['external_order_id' => '2', 'reason' => 'Out of stock'], $sent['order.rejected']['data']);
    }

    public function test_a_connection_with_no_callback_queues_no_events(): void
    {
        StoreConnections::setCallbackUrl($this->connection, null);
        $order = StoreOrders::receive($this->connection->fresh(), $this->orderData(), 'e');
        StoreOrders::reject($order, 'No', $this->user->id);

        $this->assertSame(0, StoreEvent::withoutGlobalScopes()->count());
        $this->assertSame([], $this->pushed());
    }

    public function test_the_nightly_refresh_queues_everything_and_catches_overnight_expiry(): void
    {
        $this->shop->update(['expiry_tracking_enabled' => true]);
        StockIn::where('product_id', $this->product->id)->update(['expiry_date' => now()->timezone('Asia/Karachi')->toDateString()]);
        $this->pushed();

        $this->travel(2)->days();
        $this->assertSame([], $this->changes(), 'Expiry writes nothing, so nothing has noticed.');

        $this->artisan('store:refresh-stock')->assertSuccessful();

        $sent = $this->pushed();
        $this->assertSame(0, end($sent)['data']['items'][0]['available']);
    }

    public function test_the_push_and_refresh_are_scheduled(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('store:push')->expectsOutputToContain('store:refresh-stock');
    }

    public function test_the_push_runs_last_so_a_slow_store_cant_hold_up_anything_else(): void
    {
        $commands = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->map(fn ($event) => (string) $event->command)->values();

        $this->assertStringContainsString('store:push', (string) $commands->last());
        $this->assertSame(1, $commands->filter(fn (string $command) => str_contains($command, 'store:push'))->count());
    }

    // ── Pausing ────────────────────────────────────────────────────────

    public function test_pausing_says_so_and_holds_stock_updates_until_the_resume(): void
    {
        $this->pushed();

        StoreConnections::pause($this->connection->fresh());
        $this->sell();

        $sent = $this->pushed();
        $this->assertSame(['connection.paused'], array_column(array_slice($sent, -1), 'type'));
        $this->assertSame([$this->product->id], $this->changes(), 'Held, ready for the resume.');

        StoreConnections::resume($this->connection->fresh());

        $types = collect($this->pushed())->pluck('type');
        $this->assertTrue($types->contains('connection.resumed'));
        $this->assertTrue($types->contains('inventory.updated'));
    }

    public function test_the_shop_losing_the_feature_tells_the_store_and_getting_it_back_restores(): void
    {
        $this->pushed();

        $this->shop->fresh()->update(['online_store_enabled' => false]);
        $this->assertContains('connection.paused', collect($this->pushed())->pluck('type')->all());

        $this->shop->fresh()->update(['online_store_enabled' => true]);
        $types = collect($this->pushed())->pluck('type');
        $this->assertTrue($types->contains('connection.resumed'));
        $this->assertTrue($types->contains('inventory.updated'));
    }

    public function test_order_decisions_still_go_out_while_paused(): void
    {
        $order = StoreOrders::receive($this->connection, $this->orderData(), 'e');
        StoreConnections::pause($this->connection->fresh());
        StoreOrders::reject($order, 'No', $this->user->id);

        $this->assertContains('order.rejected', collect($this->pushed())->pluck('type')->all());
    }

    public function test_removing_a_connection_stops_everything(): void
    {
        $this->sell();

        StoreConnections::remove($this->connection->fresh());

        $this->assertSame(0, StoreEvent::withoutGlobalScopes()->whereNull('delivered_at')->count());
        $this->assertSame([], $this->changes());
        $this->assertSame([], $this->pushed());
    }

    // ── Failing and retrying ───────────────────────────────────────────

    public function test_a_failure_retries_later_with_the_same_id_and_a_fresh_signature(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 500)->push('', 200)]);
        app(StoreDelivery::class)->run();

        $event = StoreEvent::sole();
        $this->assertNull($event->delivered_at);
        $this->assertSame(1, $event->attempts);
        $this->assertSame(500, $event->response_status);
        $this->assertSame('The store answered with status 500.', $this->connection->fresh()->last_delivery_error);

        // Not yet due: a run straight away doesn't call again.
        app(StoreDelivery::class)->run();
        Http::assertSentCount(1);

        $this->travel(2)->minutes();
        app(StoreDelivery::class)->run();

        $event = $event->fresh();
        $this->assertNotNull($event->delivered_at);
        $this->assertSame(2, $event->attempts);
        $this->assertNull($this->connection->fresh()->last_delivery_error);

        $recorded = collect(Http::recorded());
        $this->assertSame($recorded[0][0]->header('X-HOK-Event-Id'), $recorded[1][0]->header('X-HOK-Event-Id'));
        $this->assertNotSame($recorded[0][0]->header('X-HOK-Signature'), $recorded[1][0]->header('X-HOK-Signature'));
        $this->assertSame($recorded[0][0]->body(), $recorded[1][0]->body());
    }

    public function test_redirects_and_connection_errors_count_as_failures(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/'])]);
        app(StoreDelivery::class)->run();
        $this->assertNull(StoreEvent::sole()->delivered_at);
        Http::assertSentCount(1);

        $this->clearOutbox();
        StoreConnections::setCallbackUrl($this->connection->fresh(), null);
        StoreConnections::setCallbackUrl($this->connection->fresh(), 'https://other.pk/x');
        Http::fake(fn () => throw new ConnectionException('nope'));
        app(StoreDelivery::class)->run();
        $this->assertSame('Couldn\'t connect to the store.', $this->connection->fresh()->last_delivery_error);
    }

    public function test_a_stores_reply_is_never_kept(): void
    {
        Http::fake(['*' => Http::response('TOP-SECRET-INTERNAL-PAGE', 500)]);
        app(StoreDelivery::class)->run();

        $this->assertStringNotContainsString('TOP-SECRET', json_encode(StoreEvent::sole()->getAttributes()));
        $this->assertStringNotContainsString('TOP-SECRET', (string) $this->connection->fresh()->last_delivery_error);
    }

    public function test_a_store_thats_down_is_called_once_per_run_not_for_every_event(): void
    {
        $order = StoreOrders::receive($this->connection, $this->orderData(), 'e');
        StoreOrders::reject($order, 'No', $this->user->id);
        StoreConnections::updateSettings($this->connection->fresh(), false, 1);

        Http::fake(['*' => Http::response('', 500)]);
        app(StoreDelivery::class)->run();

        Http::assertSentCount(1);
    }

    public function test_after_a_day_of_failing_it_stops_and_tells_the_owner_who_can_restart_it(): void
    {
        $status = 503;
        Http::fake(function () use (&$status) {
            return Http::response('', $status);
        });
        app(StoreDelivery::class)->run();

        $this->travel(25)->hours();
        app(StoreDelivery::class)->run();

        $connection = $this->connection->fresh();
        $this->assertNotNull($connection->delivery_paused_at);
        $this->assertNotNull(StoreEvent::where('type', 'inventory.updated')->first()->failed_at);
        $sentSoFar = count(Http::recorded());

        // Stopped: no more calls however much changes.
        $this->sell();
        $this->travel(1)->hours();
        app(StoreDelivery::class)->run();
        $this->assertCount($sentSoFar, Http::recorded());

        $this->actingAs($this->user);
        $this->get(route('online-orders.index'))->assertSee('Updates to your online store have stopped');

        Livewire::test('settings.online-store')->assertSee('Updates to this store have stopped')->call('resumeDelivery')->assertDontSee('Updates to this store have stopped');
        $this->assertNull($connection->fresh()->delivery_paused_at);

        $status = 200;
        app(StoreDelivery::class)->run();
        $this->assertNotNull(StoreEvent::where('type', 'inventory.updated')->latest('id')->first()->delivered_at);
    }

    public function test_one_stores_failure_doesnt_hold_up_another(): void
    {
        $other = $this->storeShop('Other');
        $created = StoreConnections::create($other->id, 'other.pk', null);
        $product = $this->item($other);
        StoreConnections::publish($created['connection'], collect([$product]));
        StoreConnections::setCallbackUrl($created['connection'], 'https://other.pk/events');

        Http::fake(['myshop.pk/*' => Http::response('', 500), 'other.pk/*' => Http::response('', 200)]);
        app(StoreDelivery::class)->run();

        $this->assertNull(StoreEvent::withoutGlobalScopes()->where('shop_id', $this->shop->id)->first()->delivered_at);
        $this->assertNotNull(StoreEvent::withoutGlobalScopes()->where('shop_id', $other->id)->first()->delivered_at);
    }

    public function test_each_store_only_hears_about_its_own_shop(): void
    {
        $other = $this->storeShop('Other');
        $created = StoreConnections::create($other->id, 'other.pk', null);
        $theirs = $this->item($other, ['name' => 'Their secret']);
        StoreConnections::publish($created['connection'], collect([$theirs]));
        StoreConnections::setCallbackUrl($created['connection'], 'https://other.pk/events');

        Http::fake(['*' => Http::response('', 200)]);
        app(StoreDelivery::class)->run();

        $forMine = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'myshop.pk'));

        $this->assertNotEmpty($forMine);
        $this->assertTrue($forMine->every(fn ($pair) => ! str_contains($pair[0]->body(), (string) json_encode($theirs->id).',"external_sku"') && ! str_contains($pair[0]->body(), 'Their secret')));
    }

    public function test_the_context_is_clear_after_a_run(): void
    {
        $this->pushed();

        $this->assertNull(\App\Support\ApiShopContext::current()->shopId());
    }

    public function test_old_delivered_events_are_cleared_and_undelivered_ones_kept(): void
    {
        $this->pushed();
        $delivered = StoreEvent::sole();

        $this->travel(31)->days();
        StoreConnections::pause($this->connection->fresh());
        Http::fake(['*' => Http::response('', 500)]);
        app(StoreDelivery::class)->run();

        $this->assertNull(StoreEvent::find($delivered->id));
        $this->assertSame(1, StoreEvent::count());
    }

    public function test_the_secret_used_is_the_newest(): void
    {
        $new = StoreConnections::rotateSecret($this->connection);

        Http::fake(['*' => Http::response('', 200)]);
        app(StoreDelivery::class)->run();

        Http::assertSent(fn (Request $request) => $request->header('X-HOK-Signature')[0] === 'v1='.hash_hmac('sha256', $request->header('X-HOK-Timestamp')[0].'.'.$request->body(), $new));
    }

    // ── The address a shop types ───────────────────────────────────────

    public static function badAddresses(): array
    {
        return [
            'plain http' => ['http://myshop.pk/x'],
            'no scheme' => ['myshop.pk/x'],
            'ftp' => ['ftp://myshop.pk/x'],
            'login in it' => ['https://user:pass@myshop.pk/x'],
            'other port' => ['https://myshop.pk:8443/x'],
            'localhost' => ['https://localhost/x'],
            'sub of localhost' => ['https://a.localhost/x'],
            'internal name' => ['https://db.internal/x'],
            'loopback' => ['https://127.0.0.1/x'],
            'private ten' => ['https://10.0.0.5/x'],
            'private 172' => ['https://172.16.0.1/x'],
            'private 192' => ['https://192.168.1.1/x'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data'],
            'carrier nat' => ['https://100.64.0.1/x'],
            'zero' => ['https://0.0.0.0/x'],
            'ipv6 loopback' => ['https://[::1]/x'],
            'ipv6 private' => ['https://[fc00::1]/x'],
            'ipv6 link local' => ['https://[fe80::1]/x'],
            'ipv4 inside ipv6' => ['https://[::ffff:127.0.0.1]/x'],
        ];
    }

    #[DataProvider('badAddresses')]
    public function test_a_bad_address_is_refused_when_saving(string $url): void
    {
        $this->expectException(ValidationException::class);

        StoreConnections::setCallbackUrl($this->connection, $url);
    }

    public function test_a_name_pointing_at_a_private_address_is_refused(): void
    {
        app(StoreCallbackGuard::class)->answers = ['sneaky.pk' => ['93.184.216.34', '10.0.0.5'], 'nowhere.pk' => []];

        foreach (['https://sneaky.pk/x', 'https://nowhere.pk/x'] as $url) {
            try {
                StoreConnections::setCallbackUrl($this->connection, $url);
                $this->fail("{$url} should have been refused.");
            } catch (ValidationException) {
                $this->assertSame(self::URL, $this->connection->fresh()->callback_url);
            }
        }
    }

    public function test_a_name_that_turns_private_after_saving_isnt_called(): void
    {
        app(StoreCallbackGuard::class)->answers = ['myshop.pk' => ['127.0.0.1']];

        Http::fake(['*' => Http::response('', 200)]);
        app(StoreDelivery::class)->run();

        Http::assertNothingSent();
        $this->assertSame('The address isn\'t reachable from the internet.', $this->connection->fresh()->last_delivery_error);
        $this->assertNull(StoreEvent::sole()->delivered_at);
    }

    public function test_the_call_is_pinned_to_the_address_that_was_checked(): void
    {
        $options = null;

        Http::fake(function (Request $request, array $sent) use (&$options) {
            $options = $sent;

            return Http::response('', 200);
        });

        app(StoreDelivery::class)->run();

        $this->assertSame(['myshop.pk:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
        $this->assertFalse($options['allow_redirects']);
        $this->assertSame(10, $options['timeout']);
    }

    // ── The owner's screen ─────────────────────────────────────────────

    public function test_the_screen_saves_the_address_refuses_bad_ones_and_shows_the_log(): void
    {
        $this->pushed();
        $this->actingAs($this->user);

        Livewire::test('settings.online-store')
            ->assertSet('callbackUrl', self::URL)
            ->assertSee('inventory.updated')
            ->assertSee('Delivered')
            ->set('callbackUrl', 'http://127.0.0.1/x')
            ->call('saveCallbackUrl')
            ->assertHasErrors('callbackUrl')
            ->set('callbackUrl', 'https://new.pk/events')
            ->call('saveCallbackUrl')
            ->assertHasNoErrors();

        $this->assertSame('https://new.pk/events', $this->connection->fresh()->callback_url);
        $this->assertSame([$this->product->id], $this->changes(), 'A new address gets a full picture.');
    }

    public function test_the_screen_can_turn_updates_off(): void
    {
        $this->actingAs($this->user);

        Livewire::test('settings.online-store')->set('callbackUrl', '')->call('saveCallbackUrl')->assertHasNoErrors();

        $this->assertNull($this->connection->fresh()->callback_url);
        $this->assertSame(0, DB::table('store_changes')->count());
    }
}
