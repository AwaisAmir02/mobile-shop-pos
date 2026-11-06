<?php

namespace Tests\Feature;

use App\Actions\AcceptWhatsAppOrder;
use App\Actions\ConsumeStockFifo;
use App\Actions\UpdateSale;
use App\Actions\UpdateStockIn;
use App\Enums\FeatureKey;
use App\Enums\QuantityUnit;
use App\Enums\WhatsAppOrderStatus;
use App\Models\Product;
use App\Models\ModuleRevision;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppOrder;
use App\ReportQueries\ExpiryReport;
use App\ReportQueries\StockInReport;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
use App\Support\BatchExpiry;
use App\Support\SiteFeatures;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class ExpiryTrackingTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected function tracking(array $attributes = []): Shop
    {
        return $this->shop($attributes + ['expiry_tracking_enabled' => true]);
    }

    protected function inDays(int $days): string
    {
        return Carbon::now(Entitlements::TIMEZONE)->addDays($days)->toDateString();
    }

    /** @return array<int, ?int> the batch ids a preview draws, in order */
    protected function draws(Product $product, mixed $quantity): array
    {
        return array_column(ConsumeStockFifo::preview($product, $quantity), 'stock_in_id');
    }

    // ── Shops with the feature off ─────────────────────────────────────

    public function test_with_the_feature_off_all_three_sites_run_exactly_todays_sql(): void
    {
        $product = $this->product($this->shop());
        $batch = $this->batch($product, 5, ['expiry_date' => $this->inDays(-3)]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            if (str_contains($query->sql, 'from `stock_ins`')) {
                $queries[] = $query->sql;
            }
        });

        ConsumeStockFifo::preview($product, 1);
        ConsumeStockFifo::previewForEdit($product, 1, [$batch->id => 1]);
        Product::sellingPricesFor(collect([$product]));
        DB::transaction(fn () => ConsumeStockFifo::handle($product, 1));

        $scopes = 'and (`stock_ins`.`status` != ? or `stock_ins`.`status` is null)';
        $this->assertCount(4, $queries);

        foreach ($queries as $sql) {
            $this->assertStringEndsWith('order by `stock_date` asc, `id` asc'.(str_contains($sql, 'for update') ? ' for update' : ''), $sql);
            $this->assertStringNotContainsString('expiry_date', $sql);
        }

        $this->assertSame(
            'select * from `stock_ins` where `product_id` = ? and `quantity_remaining` > ? and `stock_ins`.`shop_id` = ? '.$this->cancelledClause().'order by `stock_date` asc, `id` asc',
            StockIn::availableBatchesFor($product->id, false)->toSql(),
        );
    }

    protected function cancelledClause(): string
    {
        $sql = StockIn::query()->toSql();

        return trim(substr($sql, strpos($sql, 'and ') !== false ? strpos($sql, 'and ') : strlen($sql))).' ';
    }

    public function test_with_the_feature_off_dates_are_ignored_and_expired_stock_still_sells(): void
    {
        $product = $this->product($this->shop());
        $old = $this->batch($product, 1, ['stock_date' => now()->subDays(5)->toDateString(), 'expiry_date' => $this->inDays(-1)]);
        $new = $this->batch($product, 1, ['expiry_date' => $this->inDays(1)]);

        $this->assertSame([$old->id, $new->id], $this->draws($product, 2));
        $this->assertSame(2, $this->sell($product, 2)->items()->count());
    }

    // ── The sell order ─────────────────────────────────────────────────

    public function test_the_order_is_soonest_unexpired_then_undated_then_expired(): void
    {
        $product = $this->product($this->tracking());
        $expired = $this->batch($product, 1, ['stock_date' => now()->subDays(9)->toDateString(), 'expiry_date' => $this->inDays(-2)]);
        $undated = $this->batch($product, 1, ['stock_date' => now()->subDays(8)->toDateString()]);
        $later = $this->batch($product, 1, ['stock_date' => now()->subDays(7)->toDateString(), 'expiry_date' => $this->inDays(30)]);
        $sooner = $this->batch($product, 1, ['expiry_date' => $this->inDays(3)]);

        $this->assertSame([$sooner->id, $later->id, $undated->id, $expired->id], $this->draws($product->fresh(), 4));
    }

    public function test_the_same_expiry_falls_back_to_received_date_then_id(): void
    {
        $product = $this->product($this->tracking());
        $date = $this->inDays(10);
        $newest = $this->batch($product, 1, ['stock_date' => now()->toDateString(), 'expiry_date' => $date]);
        $oldest = $this->batch($product, 1, ['stock_date' => now()->subDays(4)->toDateString(), 'expiry_date' => $date]);
        $tieA = $this->batch($product, 1, ['stock_date' => now()->subDays(2)->toDateString(), 'expiry_date' => $date]);
        $tieB = $this->batch($product, 1, ['stock_date' => now()->subDays(2)->toDateString(), 'expiry_date' => $date]);

        $this->assertSame([$oldest->id, $tieA->id, $tieB->id, $newest->id], $this->draws($product->fresh(), 4));
    }

    public function test_a_product_with_no_dates_stays_pure_fifo(): void
    {
        $product = $this->product($this->tracking());
        $second = $this->batch($product, 1, ['stock_date' => now()->subDay()->toDateString()]);
        $first = $this->batch($product, 1, ['stock_date' => now()->subDays(3)->toDateString()]);

        $this->assertSame([$first->id, $second->id], $this->draws($product->fresh(), 2));
    }

    public function test_every_order_site_agrees(): void
    {
        $product = $this->product($this->tracking());
        $this->batch($product, 1, ['stock_date' => now()->subDays(9)->toDateString(), 'selling_price' => 100, 'cost_price' => 50]);
        $first = $this->batch($product, 1, ['expiry_date' => $this->inDays(2), 'selling_price' => 200, 'cost_price' => 150]);
        $product = $product->fresh();

        $this->assertSame($first->id, $product->nextBatchToSell()->id);
        $this->assertSame(200.0, $product->currentSellingPrice());
        $this->assertSame(150.0, $product->currentCostPrice());
        $this->assertSame([$product->id => 200.0], Product::sellingPricesFor(collect([$product])));
        $this->assertSame($first->id, ConsumeStockFifo::previewForEdit($product, 1, [])[0]['stock_in_id']);
        $this->assertSame($first->id, ConsumeStockFifo::handle($product, 1)[0]['stock_in_id']);
    }

    public function test_price_and_cost_come_from_the_batch_actually_sold(): void
    {
        $product = $this->product($this->tracking());
        $this->batch($product, 5, ['stock_date' => now()->subDays(9)->toDateString(), 'selling_price' => 100, 'cost_price' => 50]);
        $near = $this->batch($product, 5, ['expiry_date' => $this->inDays(2), 'selling_price' => 200, 'cost_price' => 150]);

        $item = $this->sell($product, 2)->items()->first();

        $this->assertSame($near->id, $item->stock_in_id);
        $this->assertSame(200.0, (float) $item->unit_price);
        $this->assertSame(150.0, (float) $item->cost_price);
        $this->assertSame(400.0, (float) $item->line_total);
    }

    public function test_a_fractional_sale_splits_across_batches_by_expiry(): void
    {
        $product = $this->product($this->tracking());
        $near = $this->batch($product, 1.25, ['expiry_date' => $this->inDays(2)]);
        $far = $this->batch($product, 5, ['expiry_date' => $this->inDays(20)]);

        $this->sell($product, 2);

        $this->assertSame(0, $near->fresh()->quantity_remaining);
        $this->assertSame(4.25, $far->fresh()->quantity_remaining);
        $this->assertSame(4.25, $product->fresh()->stock_quantity);
    }

    public function test_expiry_is_per_variant(): void
    {
        $shop = $this->tracking(['variants_enabled' => true]);
        $service = app(ProductGroupService::class);
        [$red, $blue] = $service->addVariants($service->createGroup($shop->id, 'Juice', 'grocery', ''), ['Red', 'Blue'], 100, null);
        $redBatch = $this->batch($red->fresh(), 2, ['expiry_date' => $this->inDays(-1)]);
        $blueBatch = $this->batch($blue->fresh(), 2, ['expiry_date' => $this->inDays(5)]);

        $this->assertSame($blueBatch->id, $blue->fresh()->nextBatchToSell()->id);
        $this->assertSame(2, $this->sell($blue->fresh(), 2)->items()->first()->quantity);
        $this->assertRefused(fn () => $this->sell($red->fresh(), 1), 'expired');
        $this->assertSame(2, $redBatch->fresh()->quantity_remaining);
    }

    // ── Expired stock ──────────────────────────────────────────────────

    public function test_expired_stock_is_refused_only_once_everything_else_has_run_out(): void
    {
        $product = $this->product($this->tracking());
        $expired = $this->batch($product, 2, ['expiry_date' => $this->inDays(-1)]);
        $fresh = $this->batch($product, 1);

        $this->assertRefused(fn () => $this->sell($product->fresh(), 2.5), '1.5 kg of Basmati Rice is expired. Update Stock In or add fresh stock.');
        $this->assertSame(2, $expired->fresh()->quantity_remaining);
        $this->assertSame(1, $fresh->fresh()->quantity_remaining);
        $this->assertSame(3, $product->fresh()->stock_quantity);

        $this->sell($product->fresh(), 1);
        $this->assertSame(0, $fresh->fresh()->quantity_remaining);
    }

    public function test_the_expiry_day_is_still_sellable_and_the_next_day_is_not(): void
    {
        $product = $this->product($this->tracking());
        $batch = $this->batch($product, 5, ['expiry_date' => $this->inDays(0)]);

        $this->sell($product->fresh(), 1);

        Carbon::setTestNow(now()->addDay());
        $this->assertTrue($batch->fresh()->isExpired());
        $this->assertRefused(fn () => $this->sell($product->fresh(), 1), 'is expired');
        Carbon::setTestNow();
    }

    public function test_expired_uses_the_karachi_date_not_utc(): void
    {
        // 20:00 UTC on the 10th is already 01:00 on the 11th in Karachi.
        Carbon::setTestNow(Carbon::parse('2026-11-10 20:00:00', 'UTC'));

        $this->assertSame('2026-11-11', BatchExpiry::today());

        $product = $this->product($this->tracking());
        $this->batch($product, 1, ['expiry_date' => '2026-11-10']);
        $this->assertRefused(fn () => $this->sell($product->fresh(), 1), 'is expired');

        $other = $this->product(Shop::first(), ['name' => 'Today']);
        $this->batch($other, 1, ['expiry_date' => '2026-11-11']);
        $this->assertSame(1, $this->sell($other->fresh(), 1)->items()->count());

        Carbon::setTestNow();
    }

    public function test_accepting_a_whatsapp_order_is_refused_too_and_changes_nothing(): void
    {
        $shop = $this->tracking(['whatsapp_ordering_enabled' => true]);
        $product = $this->piece($shop);
        $this->batch($product, 2, ['expiry_date' => $this->inDays(-1)]);

        $contact = WhatsAppContact::create(['shop_id' => $shop->id, 'wa_id' => '923001234567']);
        $order = WhatsAppOrder::create(['shop_id' => $shop->id, 'whatsapp_contact_id' => $contact->id, 'status' => WhatsAppOrderStatus::Pending, 'quoted_total' => 0]);

        // No signed-in user on this path, so nothing may lean on ShopScope.
        auth()->logout();

        $this->assertRefused(fn () => AcceptWhatsAppOrder::handle(
            order: $order, lines: [['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => 10.0]],
            invoiceDiscount: 0.0, customerId: null, amountPaid: 10.0, userId: 1,
        ), 'is expired');

        $this->assertSame(2, Product::withoutGlobalScopes()->find($product->id)->stock_quantity);
        $this->assertSame(WhatsAppOrderStatus::Pending, WhatsAppOrder::withoutGlobalScopes()->find($order->id)->status);
    }

    public function test_the_sale_screen_shows_the_refusal_on_the_line(): void
    {
        $product = $this->piece($this->tracking());
        $this->batch($product, 2, ['expiry_date' => $this->inDays(-1)]);

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertSee('1 of this is expired')
            ->call('completeSale')
            ->assertHasErrors("cart.{$product->id}.quantity");

        $this->assertSame(2, $product->fresh()->stock_quantity);
    }

    public function test_an_edited_sale_can_keep_stock_it_already_held_but_cannot_draw_more(): void
    {
        $product = $this->product($this->tracking());
        $batch = $this->batch($product, 5, ['expiry_date' => $this->inDays(2)]);
        $sale = $this->sell($product, 2, paid: 450);

        Carbon::setTestNow(now()->addDays(5));

        $line = fn ($quantity) => [['product_id' => $product->id, 'product_name' => $product->name, 'product_type' => $product->type, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]];

        // The batch has expired since. Trimming the sale still works.
        UpdateSale::handle($sale, $line(1.5), 0.0, null, auth()->id(), now()->toDateString());
        $this->assertSame(3.5, $batch->fresh()->quantity_remaining);

        $this->assertRefused(fn () => UpdateSale::handle($sale->fresh(), $line(2), 0.0, null, auth()->id(), now()->toDateString()), '0.5 kg of Basmati Rice is expired');
        $this->assertSame(3.5, $batch->fresh()->quantity_remaining);

        Carbon::setTestNow();
    }

    // ── Stock In cart ──────────────────────────────────────────────────

    public function test_two_expiry_dates_for_one_product_stay_separate_batches(): void
    {
        $product = $this->product($this->tracking());

        $page = Livewire::test('stock-ins.create');

        foreach ([[2, 10], [3, 40], [1, 10]] as [$quantity, $days]) {
            $page->set('productId', (string) $product->id)
                ->set('quantity', (string) $quantity)
                ->set('costPrice', '250')
                ->set('sellingPrice', '300')
                ->set('expiryDate', $this->inDays($days))
                ->call('addLine')
                ->assertHasNoErrors();
        }

        // The third matches the first exactly, so those two add up.
        $this->assertCount(2, $page->get('cart'));

        $page->call('save');

        $batches = StockIn::where('product_id', $product->id)->orderBy('expiry_date')->get();
        $this->assertSame([3, 3], $batches->pluck('quantity')->all());
        $this->assertSame([$this->inDays(10), $this->inDays(40)], $batches->map(fn ($b) => $b->expiry_date->toDateString())->all());
        $this->assertSame(6, $product->fresh()->stock_quantity);
    }

    public function test_a_second_line_at_a_different_price_is_no_longer_silently_dropped(): void
    {
        $product = $this->piece($this->shop());

        $page = Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->set('quantity', '5')->set('costPrice', '100')->set('sellingPrice', '150')->call('addLine')
            ->set('productId', (string) $product->id)->set('quantity', '2')->set('costPrice', '120')->set('sellingPrice', '180')->call('addLine');

        $this->assertCount(2, $page->get('cart'));
        $this->assertEqualsWithDelta(740.0, $page->instance()->grandTotalCost(), 0.01);

        $page->call('save');

        $batches = StockIn::where('product_id', $product->id)->orderBy('id')->get();
        $this->assertSame([100.0, 120.0], $batches->map(fn ($b) => (float) $b->cost_price)->all());
        $this->assertSame([150.0, 180.0], $batches->map(fn ($b) => (float) $b->selling_price)->all());
    }

    public function test_either_line_of_a_product_can_be_removed(): void
    {
        $product = $this->piece($this->shop());

        $page = Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)->set('quantity', '5')->set('sellingPrice', '150')->call('addLine')
            ->set('productId', (string) $product->id)->set('quantity', '2')->set('sellingPrice', '180')->call('addLine');

        $page->call('removeLine', "{$product->id}-2");

        $this->assertSame([$product->id], array_keys($page->get('cart')));
    }

    public function test_shelf_life_pre_fills_the_date_and_it_stays_editable(): void
    {
        $product = $this->product($this->tracking(), ['shelf_life_days' => 30]);

        Livewire::test('stock-ins.create')
            ->set('stock_date', '2026-11-01')
            ->set('productId', (string) $product->id)
            ->assertSet('expiryDate', '2026-12-01')
            ->assertSee('Expiry Date');
    }

    public function test_with_the_feature_off_stock_in_has_no_date_and_ignores_a_crafted_one(): void
    {
        $product = $this->piece($this->shop(), ['shelf_life_days' => 30]);

        Livewire::test('stock-ins.create')
            ->assertDontSee('Expiry Date')
            ->set('productId', (string) $product->id)
            ->assertSet('expiryDate', '')
            ->set('expiryDate', '2030-01-01')
            ->set('quantity', '1')
            ->set('sellingPrice', '10')
            ->call('addLine')
            ->call('save');

        $this->assertNull(StockIn::where('product_id', $product->id)->first()->expiry_date);
    }

    // ── History and reports ────────────────────────────────────────────

    public function test_the_history_column_and_exports_appear_only_once_a_row_has_a_date(): void
    {
        $product = $this->product($this->tracking());
        $batch = $this->batch($product, 1);

        Livewire::test('stock-ins.history')->assertDontSee('Expires');
        $this->assertNotContains('Expires', (new StockInReport)->tableHeaders());

        $batch->update(['expiry_date' => '2030-05-01']);

        Livewire::test('stock-ins.history')->assertSee('Expires')->assertSee('01 May 2030');

        $report = new StockInReport;
        $headers = $report->tableHeaders();
        $this->assertSame('01 May 2030', array_combine($headers, $report->tableRows(forExcel: false)[0])['Expires']);
        $this->assertCount(count($headers), $report->columnTypes());
        $this->assertCount(count($headers), $report->tableRows(forExcel: true)[0]);
    }

    public function test_editing_a_date_keeps_the_old_one_in_the_snapshot(): void
    {
        $product = $this->product($this->tracking());
        $batch = $this->batch($product, 1, ['expiry_date' => '2030-05-01']);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->assertSet('editExpiryDate', '2030-05-01')
            ->set('editExpiryDate', '2031-06-02')
            ->call('saveEdit')
            ->assertHasNoErrors();

        $this->assertSame('2031-06-02', $batch->fresh()->expiry_date->toDateString());
        $this->assertSame('2030-05-01', ModuleRevision::where('revisionable_id', $batch->id)->latest('id')->first()->snapshot['expiry_date']);
    }

    public function test_an_edit_that_leaves_out_the_date_keeps_it(): void
    {
        $batch = $this->batch($this->product($this->tracking()), 1, ['expiry_date' => '2030-05-01']);

        UpdateStockIn::handle($batch, ['quantity' => 2, 'vendor_id' => null, 'stock_date' => $batch->stock_date->toDateString(), 'note' => null, 'cost_price' => null, 'selling_price' => 1.0], auth()->id());

        $this->assertSame('2030-05-01', $batch->fresh()->expiry_date->toDateString());
    }

    public function test_the_report_sorts_batches_into_expired_soon_and_undated_valued_at_cost(): void
    {
        $product = $this->product($this->tracking());
        $this->batch($product, 1.5, ['expiry_date' => $this->inDays(-1), 'cost_price' => 100]);
        $this->batch($product, 2, ['expiry_date' => $this->inDays(5), 'cost_price' => 50]);
        $this->batch($product, 1, ['expiry_date' => $this->inDays(100), 'cost_price' => 50]);
        $this->batch($product, 3, ['cost_price' => null]);

        $report = new ExpiryReport(30);

        $this->assertSame(150.0, ExpiryReport::totalAtCost($report->expired()));
        $this->assertSame(100.0, ExpiryReport::totalAtCost($report->expiringSoon()));
        $this->assertCount(1, $report->undated());
        // A batch with no cost of its own falls back to the product's.
        $this->assertSame(750.0, ExpiryReport::totalAtCost($report->undated()));

        $cards = collect($report->summaryCards())->pluck('value', 'label');
        $this->assertSame('Rs 150.00', $cards['Expired']);
        $this->assertSame('Rs 100.00', $cards['Expiring Soon']);
        $this->assertSame('1', $cards['No Expiry Date']);

        $this->assertCount(3, $report->tableRows(forExcel: true));
        $this->assertSame(1.5, $report->tableRows(forExcel: true)[0][4]);
    }

    public function test_the_expiry_screen_fills_in_a_missing_date_through_the_normal_edit(): void
    {
        $batch = $this->batch($this->product($this->tracking()), 1);

        Livewire::test('stock-ins.expiry')
            ->assertSee('Basmati Rice')
            ->call('openDate', $batch->id)
            ->set('datingDate', '2030-05-01')
            ->call('saveDate')
            ->assertHasNoErrors();

        $this->assertSame('2030-05-01', $batch->fresh()->expiry_date->toDateString());
        $this->assertSame(1, ModuleRevision::where('revisionable_id', $batch->id)->count());
    }

    public function test_the_screen_and_dashboard_card_only_exist_when_tracking_is_on(): void
    {
        $shop = $this->shop();

        $this->get(route('stock-ins.expiry'))->assertForbidden();
        Livewire::test('dashboard.index')->assertDontSee('Expiring &amp; Expired Stock', false);

        $shop->update(['expiry_tracking_enabled' => true]);
        auth()->user()->unsetRelation('shop');

        $this->get(route('stock-ins.expiry'))->assertOk();
        Livewire::test('dashboard.index')->assertSee('Expiring &amp; Expired Stock', false);
    }

    // ── Sale edit and forms ────────────────────────────────────────────

    public function test_sale_screens_note_a_near_expiry_date_from_the_preview(): void
    {
        $product = $this->piece($this->tracking());
        $this->batch($product, 5, ['expiry_date' => '2030-01-01']);
        $near = $this->product(Shop::first(), ['name' => 'Soon', 'unit' => 'piece']);
        $this->batch($near, 5, ['expiry_date' => $this->inDays(3)]);
        $label = Carbon::parse($this->inDays(3))->format('d M Y');

        Livewire::test('sales.create')
            ->call('addToCart', $product->id)
            ->assertDontSee('Expires')
            ->call('addToCart', $near->id)
            ->assertSee("Expires {$label}");

        $sale = $this->sell($near->fresh(), 1, paid: 300);

        Livewire::test('sales.edit', ['sale' => $sale])->assertSee("Expires {$label}");
    }

    public function test_both_product_forms_save_shelf_life_only_when_tracking_is_on(): void
    {
        $this->tracking();

        Livewire::test('products.index')
            ->set('type', 'grocery')->set('name', 'Milk')->set('price', '150')->set('shelf_life_days', '7')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(7, Product::where('name', 'Milk')->first()->shelf_life_days);

        Livewire::test('products.quick-create')
            ->set('type', 'grocery')->set('name', 'Curd')->set('price', '150')->set('shelf_life_days', '5')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(5, Product::where('name', 'Curd')->first()->shelf_life_days);

        Livewire::test('products.index')
            ->set('type', 'grocery')->set('name', 'Bad')->set('price', '150')->set('shelf_life_days', '0')
            ->call('save')->assertHasErrors('shelf_life_days');

        Shop::first()->update(['expiry_tracking_enabled' => false]);
        auth()->user()->unsetRelation('shop');

        Livewire::test('products.index')
            ->assertDontSee('Shelf Life')
            ->set('type', 'grocery')->set('name', 'Ignored')->set('price', '150')->set('shelf_life_days', '9')
            ->call('save')->assertHasNoErrors();
        $this->assertNull(Product::where('name', 'Ignored')->first()->shelf_life_days);
    }

    // ── Catalogue and copy ─────────────────────────────────────────────

    public function test_the_feature_is_free_off_by_default_and_out_of_the_plan_editor(): void
    {
        $this->assertFalse(Shop::create(['name' => 'New'])->fresh()->expiry_tracking_enabled);
        $this->assertFalse(FeatureKey::ExpiryTracking->planControlled());
        $this->assertNotContains(FeatureKey::ExpiryTracking, FeatureKey::planCases());
    }

    public function test_the_site_no_longer_promises_oldest_first_for_every_shop(): void
    {
        $this->assertStringNotContainsString('takes the oldest stock first', json_encode(SiteFeatures::all()));
        $this->assertStringNotContainsString('takes the oldest stock first', file_get_contents(resource_path('views/site/home.blade.php')));
    }
}
