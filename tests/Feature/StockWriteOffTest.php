<?php

namespace Tests\Feature;

use App\Actions\CancelStockIn;
use App\Actions\CancelStockWriteOff;
use App\Actions\CreateStockWriteOff;
use App\Actions\UpdateStockIn;
use App\Enums\QuantityUnit;
use App\Enums\WriteOffReason;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockInPayment;
use App\Models\StockWriteOff;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\ExpiryReport;
use App\ReportQueries\ProductReport;
use App\ReportQueries\StockInReport;
use App\ReportQueries\StockWriteOffReport;
use App\Services\Billing\Entitlements;
use App\Services\ProductGroupService;
use App\Services\ShopReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class StockWriteOffTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected function writeOff(Product $product, mixed $quantity, WriteOffReason $reason = WriteOffReason::Damaged, ?int $batchId = null, ?string $note = null): StockWriteOff
    {
        return CreateStockWriteOff::handle($product, $quantity, $reason, $note, auth()->id(), now()->toDateString(), $batchId);
    }

    protected function inDays(int $days): string
    {
        return Carbon::now(Entitlements::TIMEZONE)->addDays($days)->toDateString();
    }

    // ── Taking stock out ───────────────────────────────────────────────

    public function test_it_draws_batches_oldest_first_and_reduces_stock_with_no_sale(): void
    {
        $product = $this->product($this->shop());
        $first = $this->batch($product, 1, ['stock_date' => now()->subDays(3)->toDateString(), 'cost_price' => 200]);
        $second = $this->batch($product, 5, ['cost_price' => 300]);

        $writeOff = $this->writeOff($product, 2.5);

        $this->assertSame(0, $first->fresh()->quantity_remaining);
        $this->assertSame(3.5, $second->fresh()->quantity_remaining);
        $this->assertSame(3.5, $product->fresh()->stock_quantity);
        $this->assertSame(2.5, $writeOff->quantity);
        $this->assertSame(QuantityUnit::Kg, $writeOff->unit);
        $this->assertCount(2, $writeOff->items);
        // Each part at its own batch's cost: 1 × 200 + 1.5 × 300.
        $this->assertSame(650.0, (float) $writeOff->total_cost);
        $this->assertSame(0, \App\Models\Sale::count());
    }

    public function test_with_expiry_tracking_on_it_follows_the_expiry_order(): void
    {
        $product = $this->product($this->shop(['expiry_tracking_enabled' => true]));
        $undated = $this->batch($product, 5, ['stock_date' => now()->subDays(9)->toDateString()]);
        $near = $this->batch($product, 5, ['expiry_date' => $this->inDays(2)]);

        $this->writeOff($product, 1);

        $this->assertSame(5, $undated->fresh()->quantity_remaining);
        $this->assertSame(4, $near->fresh()->quantity_remaining);
    }

    public function test_it_can_draw_from_one_chosen_batch_including_an_expired_one(): void
    {
        $product = $this->product($this->shop(['expiry_tracking_enabled' => true]));
        $fresh = $this->batch($product, 5, ['expiry_date' => $this->inDays(30)]);
        $expired = $this->batch($product, 1.5, ['expiry_date' => $this->inDays(-1), 'cost_price' => 100]);

        $writeOff = $this->writeOff($product, 1.5, WriteOffReason::Expired, $expired->id);

        $this->assertSame(0, $expired->fresh()->quantity_remaining);
        $this->assertSame(5, $fresh->fresh()->quantity_remaining);
        $this->assertSame(5, $product->fresh()->stock_quantity);
        $this->assertSame(150.0, (float) $writeOff->total_cost);
        $this->assertSame($expired->id, $writeOff->items->first()->stock_in_id);
    }

    public function test_it_clears_an_all_expired_product_so_fresh_stock_sells_again(): void
    {
        $product = $this->product($this->shop(['expiry_tracking_enabled' => true]));
        $expired = $this->batch($product, 2, ['expiry_date' => $this->inDays(-1)]);
        $this->assertRefused(fn () => $this->sell($product->fresh(), 1), 'is expired');

        $this->writeOff($product, 2, WriteOffReason::Expired, $expired->id);
        $this->batch($product->fresh(), 3, ['expiry_date' => $this->inDays(10)]);

        $this->assertSame(1, $this->sell($product->fresh(), 1)->items()->count());
    }

    public function test_it_refuses_more_than_there_is(): void
    {
        $product = $this->product($this->shop());
        $small = $this->batch($product, 1);
        $this->batch($product, 1);

        $this->assertRefused(fn () => $this->writeOff($product, 2.5), 'Only 2 kg');
        $this->assertRefused(fn () => $this->writeOff($product, 1.5, batchId: $small->id), 'Only 1 kg left in that batch');
        $this->assertSame(2, $product->fresh()->stock_quantity);
        $this->assertSame(1, $small->fresh()->quantity_remaining);
    }

    public function test_it_follows_the_unit_rules_and_refuses_a_batch_of_another_product(): void
    {
        $shop = $this->shop();
        $cable = $this->piece($shop);
        $this->batch($cable, 5);
        $rice = $this->product($shop);
        $riceBatch = $this->batch($rice, 5);

        $this->assertRefused(fn () => $this->writeOff($cable, 1.5), 'whole number');
        $this->assertRefused(fn () => $this->writeOff($rice, 0));
        $this->assertRefused(fn () => $this->writeOff($rice, -1));
        $this->assertRefused(fn () => $this->writeOff($rice, 1.0005));
        $this->assertRefused(fn () => $this->writeOff($cable, 1, batchId: $riceBatch->id), "isn't part of this product");
        $this->assertRefused(fn () => $this->writeOff($rice, 1, WriteOffReason::Other), 'Say what happened');
    }

    public function test_stock_with_no_batch_can_be_written_off_at_the_products_own_cost(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 4, 'cost_price' => 80]);

        $writeOff = $this->writeOff($product, 1.5);

        $this->assertSame(2.5, $product->fresh()->stock_quantity);
        $this->assertNull($writeOff->items->first()->stock_in_id);
        $this->assertSame(120.0, (float) $writeOff->total_cost);
    }

    public function test_stock_with_no_recorded_cost_has_no_invented_figure(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 4, 'cost_price' => null]);

        $this->assertNull($this->writeOff($product, 1)->total_cost);
    }

    public function test_it_works_on_a_single_variant(): void
    {
        $shop = $this->shop(['variants_enabled' => true]);
        $service = app(ProductGroupService::class);
        [$red, $blue] = $service->addVariants($service->createGroup($shop->id, 'Cloth', 'grocery', '', null, QuantityUnit::Metre), ['Red', 'Blue'], 100, null);
        $this->batch($red->fresh(), 3);
        $this->batch($blue->fresh(), 3);

        $writeOff = $this->writeOff($red->fresh(), 1.5);

        $this->assertSame(1.5, $red->fresh()->stock_quantity);
        $this->assertSame(3, $blue->fresh()->stock_quantity);
        $this->assertSame('Cloth - Red', $writeOff->product_name);
        $this->assertSame(QuantityUnit::Metre, $writeOff->unit);
    }

    // ── What it leaves alone ───────────────────────────────────────────

    public function test_a_fully_paid_batch_can_be_written_off_and_what_the_vendor_is_owed_stays(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme']);
        $paid = $this->batch($product, 2, ['vendor_id' => $vendor->id, 'payment_status' => 'paid', 'total_cost' => 500, 'amount_paid' => 500]);
        $owing = $this->batch($product, 2, ['vendor_id' => $vendor->id, 'payment_status' => 'unpaid', 'total_cost' => 500, 'amount_paid' => 0]);

        $this->writeOff($product, 2, batchId: $paid->id);
        $this->writeOff($product, 1, batchId: $owing->id);

        $this->assertSame(2, $paid->fresh()->quantity);
        $this->assertSame(500.0, (float) $paid->fresh()->amount_paid);
        $this->assertSame(500.0, (float) $owing->fresh()->total_cost);
        $this->assertSame(500.0, $owing->fresh()->amountOwed());
        $this->assertSame(500.0, $vendor->fresh()->totalOwed());
    }

    public function test_it_changes_no_revenue_figure(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $this->batch($product, 5);
        $this->sell($product, 1);
        $before = app(ShopReportService::class)->summary($shop, now()->startOfDay(), now()->endOfDay());

        $this->writeOff($product->fresh(), 2);

        $after = app(ShopReportService::class)->summary($shop, now()->startOfDay(), now()->endOfDay());
        $this->assertSame($before['totalRevenue'], $after['totalRevenue']);
        $this->assertSame($before['totalItemsSold'], $after['totalItemsSold']);
        $this->assertSame($before['netSummary'], $after['netSummary']);
    }

    // ── Reversing ──────────────────────────────────────────────────────

    public function test_cancelling_restores_each_batch_exactly_and_can_only_happen_once(): void
    {
        $product = $this->product($this->shop());
        $first = $this->batch($product, 0.1, ['stock_date' => now()->subDay()->toDateString()]);
        $second = $this->batch($product, 0.2);

        $writeOff = $this->writeOff($product, 0.3);
        $this->assertSame(0, $product->fresh()->stock_quantity);

        CancelStockWriteOff::handle($writeOff, auth()->id(), 'Found it');

        $this->assertSame(0.1, $first->fresh()->quantity_remaining);
        $this->assertSame(0.2, $second->fresh()->quantity_remaining);
        $this->assertSame(0.3, $product->fresh()->stock_quantity);

        $cancelled = StockWriteOff::withoutGlobalScope(ExcludesCancelledScope::class)->find($writeOff->id);
        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame('Found it', $cancelled->cancel_reason);
        $this->assertNull(StockWriteOff::find($writeOff->id));

        $revision = ModuleRevision::where('revisionable_type', StockWriteOff::class)->sole();
        $this->assertSame(0.3, $revision->snapshot['quantity']);
        $this->assertSame('kg', $revision->snapshot['unit']);

        $this->expectException(\RuntimeException::class);
        CancelStockWriteOff::handle($cancelled, auth()->id());
    }

    public function test_cancelling_after_the_product_is_deleted_reports_it_instead_of_failing(): void
    {
        $product = $this->product($this->shop(), ['stock_quantity' => 4]);
        $writeOff = $this->writeOff($product, 1);
        $product->delete();

        $this->assertSame(['Basmati Rice'], CancelStockWriteOff::handle($writeOff->fresh(), auth()->id()));
        $this->assertSame('Basmati Rice', StockWriteOff::withoutGlobalScope(ExcludesCancelledScope::class)->find($writeOff->id)->product_name);
    }

    public function test_a_written_off_batch_says_so_when_it_cant_be_reduced_or_cancelled(): void
    {
        $product = $this->product($this->shop());
        $entry = $this->batch($product, 2);
        $writeOff = $this->writeOff($product, 1.5, batchId: $entry->id);
        $fields = ['vendor_id' => null, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 250.0, 'selling_price' => 300.0];

        $this->assertRefused(fn () => UpdateStockIn::handle($entry->fresh(), ['quantity' => 1] + $fields, auth()->id()), 'already been sold or written off');
        $this->assertRefused(fn () => CancelStockIn::handle($entry->fresh(), auth()->id()), 'already been sold or written off');

        // Once the write-off is cancelled, the stock is back and the entry can go.
        CancelStockWriteOff::handle($writeOff, auth()->id());
        CancelStockIn::handle($entry->fresh(), auth()->id());
        $this->assertSame(0, $product->fresh()->stock_quantity);
    }

    // ── Reports ────────────────────────────────────────────────────────

    public function test_product_valuation_stock_in_history_and_expiry_all_reflect_it(): void
    {
        $product = $this->product($this->shop(['expiry_tracking_enabled' => true]), ['price' => 100]);
        $batch = $this->batch($product, 4, ['selling_price' => 200, 'cost_price' => 100, 'expiry_date' => $this->inDays(-1)]);

        $this->assertSame(800.0, (new ProductReport)->totalStockValue());
        $this->assertSame(400.0, (new ProductReport)->totalCostValue());
        $this->assertSame(400.0, ExpiryReport::totalAtCost((new ExpiryReport)->expired()));

        $writeOff = $this->writeOff($product, 1.5, WriteOffReason::Expired, $batch->id);

        $this->assertSame(500.0, (new ProductReport)->totalStockValue());
        $this->assertSame(250.0, (new ProductReport)->totalCostValue());
        $this->assertSame(250.0, ExpiryReport::totalAtCost((new ExpiryReport)->expired()));

        $report = new StockInReport;
        $row = array_combine($report->tableHeaders(), $report->tableRows(forExcel: false)[0]);
        $this->assertSame('4', $row['Quantity']);
        $this->assertSame('2.5', $row['Remaining']);

        $this->writeOff($product->fresh(), 2.5, WriteOffReason::Expired, $batch->id);
        $this->assertSame(0.0, (new ProductReport)->totalCostValue());
        $this->assertCount(0, (new ExpiryReport)->expired());

        CancelStockWriteOff::handle($writeOff, auth()->id());
        $this->assertSame(150.0, (new ProductReport)->totalCostValue());
    }

    public function test_the_report_totals_active_write_offs_and_lists_cancelled_ones(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 10, ['cost_price' => 100]);
        $this->writeOff($product, 1.5);
        $cancelled = $this->writeOff($product->fresh(), 2, WriteOffReason::Lost);
        CancelStockWriteOff::handle($cancelled, auth()->id());

        $report = new StockWriteOffReport(includeCancelled: true);

        $this->assertSame(150.0, $report->totalCost());
        $this->assertSame('Rs 150.00', $report->summaryCards()[0]['value']);
        $this->assertCount(2, $report->tableRows(forExcel: false));

        $excel = collect($report->tableRows(forExcel: true))->firstWhere(7, 'Active');
        $this->assertSame(1.5, $excel[2]);
        $this->assertCount(count($report->tableHeaders()), $excel);
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());

        $this->assertCount(1, (new StockWriteOffReport(reason: 'lost', includeCancelled: true))->tableRows(forExcel: false));
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_screen_records_a_write_off(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5);

        Livewire::test('stock-ins.write-offs')
            ->assertSee('No write-offs')
            ->call('openForm')
            ->set('productId', (string) $product->id)
            ->assertSee('Quantity (kg)')
            ->set('quantity', '1.5')
            ->set('reason', 'damaged')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Basmati Rice')
            ->assertSee('1.5 kg')
            ->assertSee('Damaged');

        $this->assertSame(3.5, $product->fresh()->stock_quantity);
        $this->assertSame(auth()->id(), StockWriteOff::sole()->user_id);
    }

    public function test_the_screen_validates_by_unit_and_needs_a_note_for_other(): void
    {
        $product = $this->piece($this->shop());
        $this->batch($product, 5);

        Livewire::test('stock-ins.write-offs')
            ->set('productId', (string) $product->id)
            ->set('quantity', '1.5')
            ->call('save')
            ->assertHasErrors('quantity')
            ->set('quantity', '1')
            ->set('reason', 'other')
            ->call('save')
            ->assertHasErrors('note')
            ->set('note', 'Given away')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_the_write_off_button_arrives_with_the_expired_batch_filled_in(): void
    {
        $product = $this->product($this->shop(['expiry_tracking_enabled' => true]));
        $this->batch($product, 5, ['expiry_date' => $this->inDays(30)]);
        $expired = $this->batch($product, 1.5, ['expiry_date' => $this->inDays(-1)]);

        Livewire::test('stock-ins.expiry')->assertSee(route('stock-ins.write-offs', ['batch' => $expired->id]));

        Livewire::withQueryParams(['batch' => $expired->id])
            ->test('stock-ins.write-offs')
            ->assertSet('productId', (string) $product->id)
            ->assertSet('stockInId', (string) $expired->id)
            ->assertSet('quantity', '1.5')
            ->assertSet('reason', 'expired')
            ->assertSet('openOnLoad', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(0, $expired->fresh()->quantity_remaining);
        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_the_screen_cancels_and_restores(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5);
        $writeOff = $this->writeOff($product, 1.5);

        Livewire::test('stock-ins.write-offs')
            ->call('openCancel', $writeOff->id)
            ->set('cancelReason', 'Mistake')
            ->call('cancelWriteOff')
            ->assertSee('Cancelled');

        $this->assertSame(5, $product->fresh()->stock_quantity);
    }

    public function test_staff_without_edit_access_can_look_but_not_change_anything(): void
    {
        $shop = $this->shop();
        $product = $this->product($shop);
        $this->batch($product, 5);
        $writeOff = $this->writeOff($product, 1);

        $role = \App\Models\Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['stock-ins']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id]));

        $page = Livewire::test('stock-ins.write-offs')->assertDontSee('Write Off Stock')->assertSee('Basmati Rice');

        $page->call('openForm')->assertForbidden();
        Livewire::test('stock-ins.write-offs')->set('productId', (string) $product->id)->set('quantity', '1')->call('save')->assertForbidden();
        Livewire::test('stock-ins.write-offs')->call('openCancel', $writeOff->id)->assertForbidden();

        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_another_shops_products_and_write_offs_are_out_of_reach(): void
    {
        $other = Shop::create(['name' => 'Other']);
        $theirProduct = Product::create(['shop_id' => $other->id, 'type' => 'accessory', 'name' => 'Theirs', 'price' => 1, 'stock_quantity' => 5, 'details' => []]);
        $theirBatch = $this->batch($theirProduct, 1);
        $theirWriteOff = StockWriteOff::create(['shop_id' => $other->id, 'product_name' => 'Theirs', 'quantity' => 1, 'reason' => 'lost', 'written_off_on' => now()->toDateString()]);

        $this->shop();

        Livewire::test('stock-ins.write-offs')
            ->assertDontSee('Theirs')
            ->set('productId', (string) $theirProduct->id)
            ->set('quantity', '1')
            ->call('save')
            ->assertHasErrors('productId');

        Livewire::withQueryParams(['batch' => $theirBatch->id])->test('stock-ins.write-offs')->assertSet('productId', '');

        $this->assertSame(6, Product::withoutGlobalScopes()->find($theirProduct->id)->stock_quantity);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('stock-ins.write-offs')->call('openCancel', $theirWriteOff->id);
    }
}
