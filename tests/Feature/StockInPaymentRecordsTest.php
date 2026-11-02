<?php

namespace Tests\Feature;

use App\Actions\CancelStockIn;
use App\Enums\StockInPaymentSource;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Support\StockInPaymentBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * StockIn.amount_paid stays the stored total every reader uses. Each of the
 * three write paths that changes it also writes one dated stock_in_payments
 * row, so a Stock-In's rows always add up to its amount_paid — checked here
 * with the same reconcile SQL that is run in production.
 */
class StockInPaymentRecordsTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function product(Shop $shop, string $name, float $cost): Product
    {
        return Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => $name, 'price' => $cost * 1.5, 'cost_price' => $cost, 'stock_quantity' => 0, 'details' => []]);
    }

    protected function entry(Shop $shop, array $attributes): StockIn
    {
        return StockIn::create(array_merge([
            'shop_id' => $shop->id, 'product_id' => null, 'product_name' => 'Item', 'quantity' => 1,
            'total_cost' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0, 'stock_date' => '2026-08-01',
        ], $attributes));
    }

    protected function assertReconciled(): void
    {
        $this->assertSame([], DB::select(StockInPaymentBackfill::RECONCILE_SQL), 'Every Stock-In\'s payment rows must add up to its amount_paid.');
    }

    // ── Write path 1: Stock-In create ────────────────────────────────

    public function test_a_paid_stock_in_records_a_purchase_time_payment_dated_at_the_stock_date(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $product = $this->product($shop, 'Charger', 1000);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '10')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('stock_date', '2026-07-15')
            ->call('save')
            ->assertHasNoErrors();

        $entry = StockIn::firstOrFail();
        $payment = StockInPayment::sole();

        $this->assertSame($entry->id, $payment->stock_in_id);
        $this->assertSame($shop->id, $payment->shop_id);
        $this->assertSame($owner->id, $payment->user_id);
        $this->assertSame(10000.0, (float) $payment->amount);
        $this->assertSame('2026-07-15', $payment->payment_date->toDateString());
        $this->assertSame(StockInPaymentSource::Initial, $payment->source);
        $this->assertReconciled();
    }

    public function test_an_unpaid_stock_in_records_no_payment(): void
    {
        [$shop] = $this->shopOwner();
        $product = $this->product($shop, 'Charger', 1000);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $product->id)
            ->set('quantity', '2')
            ->set('sellingPrice', '1500')
            ->call('addLine')
            ->set('paymentStatus', 'unpaid')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, StockIn::count());
        $this->assertSame(0, StockInPayment::count());
        $this->assertReconciled();
    }

    public function test_a_partial_multi_product_receipt_records_one_row_per_line_matching_its_split(): void
    {
        [$shop] = $this->shopOwner();
        $a = $this->product($shop, 'Cable', 300);
        $b = $this->product($shop, 'Cover', 700);

        Livewire::test('stock-ins.create')
            ->set('productId', (string) $a->id)
            ->set('quantity', '3')
            ->set('sellingPrice', '450')
            ->call('addLine')
            ->set('productId', (string) $b->id)
            ->set('quantity', '7')
            ->set('sellingPrice', '1050')
            ->call('addLine')
            ->set('paymentStatus', 'partial')
            ->set('amountPaid', '1000')
            ->set('stock_date', '2026-06-01')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, StockInPayment::count());
        foreach (StockIn::all() as $entry) {
            $this->assertSame((float) $entry->amount_paid, (float) $entry->payments()->sum('amount'));
        }
        $this->assertSame(1000.0, (float) StockInPayment::sum('amount'));
        $this->assertSame(['2026-06-01'], StockInPayment::pluck('payment_date')->map->toDateString()->unique()->values()->all());
        $this->assertReconciled();
    }

    // ── Write path 2: Add payment ────────────────────────────────────

    public function test_each_added_payment_records_its_own_dated_row(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => 5000]);

        foreach (['2000', '1250.50'] as $amount) {
            Livewire::test('stock-ins.history')
                ->call('openAddPayment', $entry->id)
                ->set('paymentAmount', $amount)
                ->call('submitPayment')
                ->assertHasNoErrors();
        }

        $rows = $entry->payments()->orderBy('id')->get();
        $this->assertSame([2000.0, 1250.5], $rows->map(fn ($r) => (float) $r->amount)->all());
        $this->assertTrue($rows->every(fn ($r) => $r->source === StockInPaymentSource::Payment && $r->user_id === $owner->id && $r->payment_date->isToday()));
        $this->assertSame(3250.5, (float) $entry->fresh()->amount_paid);
        $this->assertSame(1749.5, $entry->fresh()->amountOwed());
        $this->assertReconciled();
    }

    public function test_an_overpayment_is_still_accepted_exactly_as_before_and_recorded_as_paid(): void
    {
        [$shop] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => 1000]);

        Livewire::test('stock-ins.history')
            ->call('openAddPayment', $entry->id)
            ->set('paymentAmount', '1500')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $entry->refresh();
        $this->assertSame(1500.0, (float) $entry->amount_paid);
        $this->assertSame('paid', $entry->payment_status->value);
        $this->assertSame(0.0, $entry->amountOwed());
        $this->assertSame(1500.0, (float) $entry->payments()->sum('amount'));
        $this->assertReconciled();
    }

    // ── Write path 3: Mark as paid ───────────────────────────────────

    public function test_mark_as_paid_records_only_the_remaining_gap(): void
    {
        [$shop] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => 3000]);

        Livewire::test('stock-ins.history')
            ->call('openAddPayment', $entry->id)
            ->set('paymentAmount', '1000')
            ->call('submitPayment');
        Livewire::test('stock-ins.history')->call('markAsPaid', $entry->id);

        $markPaid = $entry->payments()->where('source', StockInPaymentSource::MarkPaid)->sole();
        $this->assertSame(2000.0, (float) $markPaid->amount);
        $this->assertTrue($markPaid->payment_date->isToday());
        $this->assertSame(3000.0, (float) $entry->fresh()->amount_paid);
        $this->assertSame(0.0, $entry->fresh()->amountOwed());
        $this->assertReconciled();
    }

    public function test_mark_as_paid_without_a_cost_price_records_no_row(): void
    {
        [$shop] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => null]);

        Livewire::test('stock-ins.history')->call('markAsPaid', $entry->id);

        $this->assertSame('paid', $entry->fresh()->payment_status->value);
        $this->assertSame(0, StockInPayment::count());
        $this->assertReconciled();
    }

    // ── Readers unchanged, cancellation, isolation ───────────────────

    public function test_amount_owed_and_vendor_total_owed_are_unchanged_by_payment_rows(): void
    {
        [$shop] = $this->shopOwner();
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Ali Traders']);
        $a = $this->entry($shop, ['vendor_id' => $vendor->id, 'total_cost' => 4000]);
        $b = $this->entry($shop, ['vendor_id' => $vendor->id, 'total_cost' => 2500, 'payment_status' => 'partial', 'amount_paid' => 500]);
        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        $before = [$a->fresh()->amountOwed(), $b->fresh()->amountOwed(), $vendor->fresh()->totalOwed()];

        Livewire::test('stock-ins.history')->call('openAddPayment', $a->id)->set('paymentAmount', '1500')->call('submitPayment');
        Livewire::test('stock-ins.history')->call('markAsPaid', $b->id);

        $this->assertSame([4000.0, 2000.0, 6000.0], $before);
        $this->assertSame(2500.0, $a->fresh()->amountOwed());
        $this->assertSame(0.0, $b->fresh()->amountOwed());
        $this->assertSame(2500.0, $vendor->fresh()->totalOwed());
        $this->assertReconciled();
    }

    public function test_cancelling_a_stock_in_keeps_its_payment_rows_on_record(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => 1000, 'quantity_remaining' => 1]);
        Livewire::test('stock-ins.history')->call('openAddPayment', $entry->id)->set('paymentAmount', '400')->call('submitPayment');

        CancelStockIn::handle($entry, $owner->id, 'Wrong supplier');

        $this->assertTrue(StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->find($entry->id)->isCancelled());
        $this->assertSame(400.0, (float) StockInPayment::where('stock_in_id', $entry->id)->sum('amount'));
        $this->assertReconciled();
    }

    public function test_payment_rows_are_isolated_between_shops(): void
    {
        [$shopA] = $this->shopOwner();
        $shopB = Shop::create(['name' => 'Shop B']);
        $entryB = $this->entry($shopB, ['payment_status' => 'paid', 'amount_paid' => 1000]);
        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        $this->assertSame(0, StockInPayment::count());
        $this->assertSame(1, StockInPayment::withoutGlobalScopes()->where('stock_in_id', $entryB->id)->count());
        $this->assertSame($shopB->id, StockInPayment::withoutGlobalScopes()->first()->shop_id);
    }

    // ── Production SQL ───────────────────────────────────────────────

    public function test_the_backfill_sql_brings_forward_existing_amounts_paid_and_is_safe_to_rerun(): void
    {
        [$shop] = $this->shopOwner();
        $paid = $this->entry($shop, ['total_cost' => 2000, 'payment_status' => 'paid', 'amount_paid' => 2000, 'stock_date' => '2026-03-10']);
        $partial = $this->entry($shop, ['total_cost' => 3000, 'payment_status' => 'partial', 'amount_paid' => 750.25, 'stock_date' => '2026-04-02']);
        $unpaid = $this->entry($shop, ['total_cost' => 900]);
        $cancelled = $this->entry($shop, ['total_cost' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]);
        $cancelled->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->assertNotSame([], DB::select(StockInPaymentBackfill::RECONCILE_SQL));

        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);
        $this->assertReconciled();

        $row = StockInPayment::where('stock_in_id', $partial->id)->sole();
        $this->assertSame(750.25, (float) $row->amount);
        $this->assertSame('2026-04-02', $row->payment_date->toDateString());
        $this->assertSame(StockInPaymentSource::Backfill, $row->source);
        $this->assertNull($row->user_id);
        $this->assertSame('2026-03-10', StockInPayment::where('stock_in_id', $paid->id)->sole()->payment_date->toDateString());
        $this->assertSame(0, StockInPayment::where('stock_in_id', $unpaid->id)->count());
        $this->assertSame(1, StockInPayment::where('stock_in_id', $cancelled->id)->count());

        // A payment made through the app after the backfill, then the SQL run again.
        Livewire::test('stock-ins.history')->call('openAddPayment', $partial->id)->set('paymentAmount', '250')->call('submitPayment');
        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        $this->assertSame(4, StockInPayment::count());
        $this->assertReconciled();
    }

    public function test_the_backfill_sql_tops_up_a_payment_made_by_old_code_before_it_runs(): void
    {
        [$shop] = $this->shopOwner();
        $entry = $this->entry($shop, ['total_cost' => 1000, 'payment_status' => 'partial', 'amount_paid' => 300]);
        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        // Old code (before the upload) adds to amount_paid without writing a row.
        $entry->update(['amount_paid' => 450]);
        $this->assertNotSame([], DB::select(StockInPaymentBackfill::RECONCILE_SQL));

        DB::statement(StockInPaymentBackfill::BACKFILL_SQL);

        $this->assertSame([300.0, 150.0], $entry->payments()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertReconciled();
    }
}
