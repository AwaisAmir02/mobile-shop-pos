<?php

namespace Tests\Feature;

use App\Actions\CancelGrn;
use App\Actions\CancelSale;
use App\Actions\ReceiveGoods;
use App\Actions\SavePurchaseOrder;
use App\Actions\UpdateStockIn;
use App\Actions\VendorInvoices;
use App\Enums\PaymentStatus;
use App\Enums\QuantityUnit;
use App\Enums\StockInPaymentSource;
use App\Enums\VendorInvoiceStatus;
use App\Models\Branch;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\SaleItem;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\ReportQueries\VendorInvoiceReport;
use App\Support\BranchContext;
use App\Support\VendorInvoiceMatch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class VendorInvoiceTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Vendor $vendor;
    protected Shop $theShop;
    protected Product $cable;
    protected Product $charger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = $this->shop(['purchase_orders_enabled' => true]);
        $this->vendor = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Acme']);
        $this->cable = $this->piece($this->theShop);
        $this->charger = $this->piece($this->theShop, ['name' => 'Charger']);
    }

    /** Receives $quantity of each at Rs $cost; expected cost on the order defaults to the same. */
    protected function received(array $items, ?int $branchId = null, ?Vendor $vendor = null): array
    {
        $order = SavePurchaseOrder::handle(null, [
            'vendor_id' => ($vendor ?? $this->vendor)->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null,
        ], array_map(fn (array $item) => ['product_id' => $item['product']->id, 'quantity' => $item['quantity'], 'expected_cost' => $item['expected'] ?? $item['cost']], $items), auth()->id());

        $grn = ReceiveGoods::handle($order->fresh(), array_map(fn (array $item, int $i) => [
            'po_line_id' => $order->lines[$i]->id, 'quantity' => $item['quantity'], 'cost_price' => $item['cost'], 'selling_price' => 150.0,
        ], $items, array_keys($items)), now()->toDateString(), $branchId, null, auth()->id());

        return $grn->lines->all();
    }

    protected function one(mixed $quantity = 10, ?float $cost = 100.0, array $extra = []): int
    {
        return $this->received([['product' => $this->cable, 'quantity' => $quantity, 'cost' => $cost] + $extra])[0]->id;
    }

    protected function bill(array $lines, array $charges = [], string $number = 'INV-1', ?Vendor $vendor = null): VendorInvoice
    {
        return VendorInvoices::record(
            ['vendor_id' => ($vendor ?? $this->vendor)->id, 'invoice_number' => $number, 'invoice_date' => now()->toDateString()],
            array_map(fn (array $line) => ['grn_line_id' => $line[0], 'billed_quantity' => $line[1], 'billed_amount' => $line[2]], $lines),
            $charges,
            auth()->id(),
        );
    }

    protected function approve(VendorInvoice $invoice, string $reason = 'Agreed with vendor'): VendorInvoice
    {
        return VendorInvoices::approve($invoice, $reason, auth()->id());
    }

    protected function row(int $grnLineId): StockIn
    {
        return StockIn::where('grn_line_id', $grnLineId)->sole();
    }

    // ── Dues have one source ───────────────────────────────────────────

    public function test_recording_creates_no_payable_dues_stay_on_the_batches(): void
    {
        $line = $this->one();
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());

        $invoice = $this->bill([[$line, 10, 1200]]);

        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed(), 'Nothing moves before approval.');
        $this->assertSame(1, StockIn::count());
    }

    // ── Tolerance ──────────────────────────────────────────────────────

    public function test_within_rs_1_is_approved_at_once_and_nothing_moves(): void
    {
        $line = $this->one();

        $invoice = $this->bill([[$line, 10, 1000.75]]);

        $this->assertSame(VendorInvoiceStatus::Approved, $invoice->status);
        $this->assertTrue($invoice->auto_passed);
        $this->assertSame(1000.0, (float) $this->row($line)->total_cost);
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());
        $this->assertFalse($invoice->lines->first()->amended);
    }

    public function test_exactly_rs_1_passes_and_a_paisa_more_does_not(): void
    {
        $this->assertSame(VendorInvoiceStatus::Approved, $this->bill([[$this->one(), 10, 1001]], number: 'A')->status);
        $this->assertSame(VendorInvoiceStatus::Flagged, $this->bill([[$this->one(), 10, 1001.01]], number: 'B')->status);
    }

    public function test_tolerance_adds_each_lines_size_so_offsetting_errors_are_flagged(): void
    {
        [$a, $b] = $this->received([['product' => $this->cable, 'quantity' => 10, 'cost' => 100.0], ['product' => $this->charger, 'quantity' => 10, 'cost' => 100.0]]);

        // Net +0.50, but really 500 too much on one and 499.50 too little on the other.
        $invoice = $this->bill([[$a->id, 10, 1500], [$b->id, 10, 500.50]]);

        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);
        $this->assertSame(999.5, VendorInvoices::evaluate($invoice)['difference_total']);
    }

    public function test_a_quantity_difference_is_flagged_however_small_the_value(): void
    {
        $line = $this->one(10, 0.05);

        $invoice = $this->bill([[$line, 9, 0.45]]);

        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);
        $this->assertStringContainsString('billed 9, received 10', implode(' ', VendorInvoices::evaluate($invoice)['reasons']));
    }

    public function test_tolerance_runs_again_at_approval_because_costs_can_change_in_between(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1200]]);
        $seen = VendorInvoices::evaluate($invoice)['fingerprint'];

        UpdateStockIn::handle($this->row($line), ['quantity' => 10, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 120.0, 'selling_price' => 150.0], auth()->id());

        $this->assertRefused(fn () => VendorInvoices::approve($invoice, 'OK', auth()->id(), $seen), 'changed while you were reviewing');

        $this->assertTrue(VendorInvoices::evaluate($invoice->fresh())['within_tolerance']);
        $approved = VendorInvoices::approve($invoice->fresh(), 'OK', auth()->id(), VendorInvoices::evaluate($invoice->fresh())['fingerprint']);

        $this->assertFalse($approved->lines->first()->amended, 'Now matching, so there was nothing to correct.');
    }

    // ── Above the purchase order ───────────────────────────────────────

    public function test_a_price_above_the_order_flags_even_when_it_matches_the_receipt_and_moves_no_money(): void
    {
        $line = $this->one(10, 120.0, ['expected' => 100.0]);

        $invoice = $this->bill([[$line, 10, 1200]]);

        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);
        $this->assertStringContainsString('Rs 200.00 above the purchase order', implode(' ', VendorInvoices::evaluate($invoice)['reasons']));
        $this->assertSame(1200.0, $this->vendor->fresh()->totalOwed());

        $approved = $this->approve($invoice);

        $this->assertFalse($approved->lines->first()->amended);
        $this->assertSame(1200.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_a_price_below_the_order_does_not_flag(): void
    {
        $line = $this->one(10, 80.0, ['expected' => 100.0]);

        $this->assertSame(VendorInvoiceStatus::Approved, $this->bill([[$line, 10, 800]])->status);
    }

    // ── Structure ──────────────────────────────────────────────────────

    public function test_one_invoice_can_cover_several_receipts_and_orders_for_one_vendor(): void
    {
        $a = $this->received([['product' => $this->cable, 'quantity' => 2, 'cost' => 100.0]])[0]->id;
        $b = $this->received([['product' => $this->charger, 'quantity' => 3, 'cost' => 100.0]])[0]->id;

        $invoice = $this->bill([[$a, 2, 200], [$b, 3, 300]]);

        $this->assertCount(2, $invoice->lines);
        $this->assertSame(500.0, $invoice->billedTotal());
    }

    public function test_another_vendors_receipt_cannot_be_on_this_invoice(): void
    {
        $other = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Other']);
        $line = $this->received([['product' => $this->cable, 'quantity' => 2, 'cost' => 100.0]], vendor: $other)[0]->id;

        $this->assertRefused(fn () => $this->bill([[$line, 2, 200]]), "isn't an active receipt from this vendor");
    }

    public function test_a_received_line_can_be_on_only_one_active_invoice(): void
    {
        $line = $this->one();
        $first = $this->bill([[$line, 10, 1000]]);

        $this->assertRefused(fn () => $this->bill([[$line, 10, 1000]], number: 'INV-2'), 'already on another invoice');

        VendorInvoices::cancel($first, auth()->id());
        $this->assertSame(VendorInvoiceStatus::Approved, $this->bill([[$line, 10, 1000]], number: 'INV-2')->status);
    }

    public function test_the_database_itself_refuses_a_second_active_line(): void
    {
        $line = $this->one();
        $first = $this->bill([[$line, 10, 1000]])->lines->first();

        $this->expectException(QueryException::class);
        $copy = $first->replicate();
        $copy->save();
    }

    public function test_an_invoice_number_is_unique_per_vendor_among_active_invoices(): void
    {
        $this->bill([[$this->one(), 10, 1000]], number: 'SAME');
        $line = $this->one();

        $this->assertRefused(fn () => $this->bill([[$line, 10, 1000]], number: 'SAME'), 'already has an invoice numbered');

        $other = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Other']);
        $theirs = $this->received([['product' => $this->charger, 'quantity' => 1, 'cost' => 100.0]], vendor: $other)[0]->id;
        $this->assertSame('SAME', $this->bill([[$theirs, 1, 100]], number: 'SAME', vendor: $other)->invoice_number);
    }

    // ── Approval corrects cost ─────────────────────────────────────────

    public function test_approval_holds_an_exact_billed_total_that_is_not_cost_times_quantity(): void
    {
        $line = $this->one(3, 30.0);

        $this->approve($this->bill([[$line, 3, 100]]));

        $row = $this->row($line);
        $this->assertSame(100.0, (float) $row->total_cost);
        $this->assertSame(33.33, (float) $row->cost_price);
        $this->assertSame(100.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_approval_writes_a_revision_and_a_reason_for_each_row_and_keeps_the_old_figures(): void
    {
        $line = $this->one();

        $invoice = $this->approve($this->bill([[$line, 10, 1200]]), 'Vendor raised price');

        $revision = ModuleRevision::where('revisionable_type', StockIn::class)->where('reason', 'Vendor raised price')->sole();
        $this->assertEquals(1000, $revision->snapshot['total_cost']);

        $stored = $invoice->lines->first();
        $this->assertTrue($stored->amended);
        $this->assertSame(1000.0, (float) $stored->previous_total_cost);
        $this->assertSame(10, $stored->unsold_quantity_at_approval);
        $this->assertSame('Vendor raised price', $invoice->review_note);
    }

    public function test_approval_needs_a_reason(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1200]]);

        $this->assertRefused(fn () => VendorInvoices::approve($invoice, '  ', auth()->id()), 'Say why');
    }

    public function test_raising_the_cost_of_a_paid_batch_makes_it_owed_again(): void
    {
        $line = $this->one();
        Livewire::test('stock-ins.history')->call('markAsPaid', $this->row($line)->id);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());

        $this->approve($this->bill([[$line, 10, 1200]]));

        $row = $this->row($line);
        $this->assertSame(PaymentStatus::Partial, $row->payment_status);
        $this->assertSame(200.0, $row->amountOwed());
        $this->assertSame(200.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_lowering_the_cost_to_exactly_what_was_paid_makes_it_paid(): void
    {
        $line = $this->one();
        \App\Actions\RecordStockInPayment::add($this->row($line), 800, auth()->id());

        $this->approve($this->bill([[$line, 10, 800]]));

        $this->assertSame(PaymentStatus::Paid, $this->row($line)->payment_status);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_a_cost_below_what_was_paid_is_refused_and_says_to_dispute(): void
    {
        $line = $this->one();
        \App\Actions\RecordStockInPayment::add($this->row($line), 900, auth()->id());
        $invoice = $this->bill([[$line, 10, 800]]);

        $this->assertRefused(fn () => $this->approve($invoice), 'dispute this invoice instead');

        $this->assertSame(1000.0, (float) $this->row($line)->total_cost);
        $this->assertTrue($invoice->fresh()->isFlagged());
    }

    public function test_one_refused_line_leaves_every_line_untouched(): void
    {
        [$a, $b] = $this->received([['product' => $this->cable, 'quantity' => 10, 'cost' => 100.0], ['product' => $this->charger, 'quantity' => 10, 'cost' => 100.0]]);
        \App\Actions\RecordStockInPayment::add($this->row($b->id), 900, auth()->id());
        $invoice = $this->bill([[$a->id, 10, 1200], [$b->id, 10, 800]]);

        $this->assertRefused(fn () => $this->approve($invoice), 'dispute this invoice instead');

        $this->assertSame(1000.0, (float) $this->row($a->id)->total_cost);
    }

    public function test_more_billed_than_received_is_never_owed_only_the_price_changes(): void
    {
        $line = $this->one(8, 100.0);

        // Billed 10 at 120 each, only 8 arrived.
        $this->approve($this->bill([[$line, 10, 1200]]));

        $row = $this->row($line);
        $this->assertSame(960.0, (float) $row->total_cost);
        $this->assertSame(120.0, (float) $row->cost_price);
        $this->assertSame(8, $row->quantity);
        $this->assertSame(8, $this->cable->fresh()->stock_quantity);
    }

    public function test_less_billed_than_received_still_owes_for_what_arrived(): void
    {
        $line = $this->one(10, 100.0);

        $this->approve($this->bill([[$line, 8, 800]]));

        $this->assertSame(1000.0, (float) $this->row($line)->total_cost);
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_a_batch_received_with_no_cost_gets_one_from_the_bill_and_is_always_reviewed(): void
    {
        $line = $this->one(10, null, ['expected' => null]);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());

        $invoice = $this->bill([[$line, 10, 0.50]]);
        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);

        $this->approve($invoice);

        $this->assertSame(0.5, (float) $this->row($line)->total_cost);
        $this->assertSame(0.5, $this->vendor->fresh()->totalOwed());
    }

    public function test_measured_quantities_match_exactly_and_correct_properly(): void
    {
        $rice = $this->product($this->theShop);
        $line = $this->received([['product' => $rice, 'quantity' => 2.5, 'cost' => 300.0]])[0]->id;

        $this->assertSame(VendorInvoiceStatus::Approved, $this->bill([[$line, 2.5, 750]], number: 'OK')->status);
        VendorInvoices::cancel(VendorInvoice::sole(), auth()->id());

        $this->approve($this->bill([[$line, 2.5, 800]], number: 'UP'));
        $this->assertSame(800.0, (float) $this->row($line)->total_cost);
        $this->assertSame(320.0, (float) $this->row($line)->cost_price);

        $this->assertSame(QuantityUnit::Kg, $this->row($line)->unit);
    }

    // ── Sold units ─────────────────────────────────────────────────────

    public function test_sold_units_keep_old_cost_unsold_use_the_new_one_and_the_owner_is_told(): void
    {
        $line = $this->one();
        $sale = $this->sell($this->cable, 6);
        $invoice = $this->bill([[$line, 10, 1200]]);

        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])
            ->assertSee('6 of 10 already sold. Those keep the old cost in Gross Profit; the 4 unsold use the new cost. What you owe the vendor changes in full.');

        $this->approve($invoice);

        $this->assertSame(100.0, (float) SaleItem::where('sale_id', $sale->id)->sole()->cost_price, 'Frozen at sale.');
        $this->assertSame(120.0, (float) $this->row($line)->cost_price);
        $this->assertSame(1200.0, $this->vendor->fresh()->totalOwed(), 'Dues change in full.');
        $this->assertSame(4, $invoice->fresh()->lines->first()->unsold_quantity_at_approval);

        $next = $this->sell($this->cable, 1);
        $this->assertSame(120.0, (float) SaleItem::where('sale_id', $next->id)->sole()->cost_price);
    }

    public function test_the_note_covers_nothing_sold_and_everything_sold(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1200]]);

        $this->assertStringContainsString('None sold yet, so all 10 use the new cost', $invoice->lines->first()->soldNote());

        $this->sell($this->cable, 10);
        $this->assertStringContainsString('All 10 already sold', $invoice->fresh()->lines->first()->soldNote());
    }

    public function test_a_cancelled_sales_units_resell_at_the_corrected_cost(): void
    {
        $line = $this->one();
        $sale = $this->sell($this->cable, 6);
        $this->approve($this->bill([[$line, 10, 1200]]));

        CancelSale::handle($sale, auth()->id());
        $this->assertSame(10, $this->row($line)->quantity_remaining);

        $again = $this->sell($this->cable, 6);

        $this->assertSame(120.0, (float) SaleItem::where('sale_id', $again->id)->sole()->cost_price);
        $this->assertSame(1200.0, $this->vendor->fresh()->totalOwed());
    }

    // ── Whole-invoice charges ──────────────────────────────────────────

    public function test_delivery_is_shared_across_lines_by_value_and_moves_dues(): void
    {
        [$a, $b] = $this->received([['product' => $this->cable, 'quantity' => 10, 'cost' => 100.0], ['product' => $this->charger, 'quantity' => 10, 'cost' => 300.0]]);

        $invoice = $this->bill([[$a->id, 10, 1000], [$b->id, 10, 3000]], [['label' => 'Delivery', 'amount' => 400]]);
        $this->assertSame(VendorInvoiceStatus::Flagged, $invoice->status);
        $this->assertSame(4400.0, $invoice->billedTotal());

        $this->approve($invoice);

        $this->assertSame(1100.0, (float) $this->row($a->id)->total_cost);
        $this->assertSame(3300.0, (float) $this->row($b->id)->total_cost);
        $this->assertSame(4400.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_a_discount_lowers_dues_and_rounding_loses_nothing(): void
    {
        [$a, $b, $c] = $this->received([
            ['product' => $this->cable, 'quantity' => 1, 'cost' => 100.0],
            ['product' => $this->charger, 'quantity' => 1, 'cost' => 100.0],
            ['product' => $this->piece($this->theShop, ['name' => 'Case']), 'quantity' => 1, 'cost' => 100.0],
        ]);

        $this->approve($this->bill([[$a->id, 1, 100], [$b->id, 1, 100], [$c->id, 1, 100]], [['label' => 'Discount', 'amount' => -10]]));

        $this->assertSame(290.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_a_small_round_off_passes_and_changes_nothing(): void
    {
        $line = $this->one();

        $invoice = $this->bill([[$line, 10, 1000]], [['label' => 'Round-off', 'amount' => 0.40]]);

        $this->assertTrue($invoice->auto_passed);
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_charges_with_nothing_to_share_them_across_are_refused(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 0]], [['label' => 'Delivery', 'amount' => 100]]);

        $this->assertRefused(fn () => $this->approve($invoice), "can't be shared");
    }

    // ── Disputes ───────────────────────────────────────────────────────

    public function test_a_dispute_changes_nothing_and_holds_the_lines_until_cancelled(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1500]]);

        $disputed = VendorInvoices::dispute($invoice, 'Wrong price', auth()->id());

        $this->assertSame(VendorInvoiceStatus::Disputed, $disputed->status);
        $this->assertSame(1000.0, (float) $this->row($line)->total_cost);
        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());
        $this->assertRefused(fn () => $this->bill([[$line, 10, 1000]], number: 'INV-2'), 'already on another invoice');
        $this->assertRefused(fn () => $this->approve($disputed), 'waiting for review');

        VendorInvoices::cancel($disputed, auth()->id());

        $this->assertSame(VendorInvoiceStatus::Approved, $this->bill([[$line, 10, 1000]])->status, 'The number and lines are free again.');
    }

    // ── Cancelling an approved invoice ─────────────────────────────────

    public function test_cancelling_an_approved_invoice_puts_the_batches_back(): void
    {
        $line = $this->one(3, 30.0);
        $invoice = $this->approve($this->bill([[$line, 3, 100]]));

        VendorInvoices::cancel($invoice, auth()->id(), 'Wrong bill');

        $row = $this->row($line);
        $this->assertSame(90.0, (float) $row->total_cost);
        $this->assertSame(30.0, (float) $row->cost_price);
        $this->assertSame(90.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame('Wrong bill', ModuleRevision::where('revisionable_type', VendorInvoice::class)->sole()->reason);
    }

    public function test_cancelling_is_refused_if_paid_more_than_the_restored_cost(): void
    {
        $line = $this->one();
        $invoice = $this->approve($this->bill([[$line, 10, 1200]]));
        VendorInvoices::pay($invoice, 1100, auth()->id());

        $this->assertRefused(fn () => VendorInvoices::cancel($invoice, auth()->id()), "so it can't be cancelled");

        $this->assertSame(1200.0, (float) $this->row($line)->total_cost);
    }

    public function test_cancelling_restores_payment_status(): void
    {
        $line = $this->one();
        \App\Actions\RecordStockInPayment::add($this->row($line), 1000, auth()->id());
        $invoice = $this->approve($this->bill([[$line, 10, 1200]]));
        $this->assertSame(PaymentStatus::Partial, $this->row($line)->payment_status);

        VendorInvoices::cancel($invoice, auth()->id());

        $this->assertSame(PaymentStatus::Paid, $this->row($line)->payment_status);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
    }

    // ── Guards ─────────────────────────────────────────────────────────

    public function test_stock_in_history_cannot_change_a_cost_an_approved_invoice_set(): void
    {
        $line = $this->one(3, 30.0);
        $this->approve($this->bill([[$line, 3, 100]]));
        $row = $this->row($line);
        $fields = ['quantity' => 3, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'selling_price' => 150.0];

        $this->assertRefused(fn () => UpdateStockIn::handle($row, $fields + ['cost_price' => 50.0], auth()->id()), 'approved vendor invoice set this cost');
        $this->assertRefused(fn () => UpdateStockIn::handle($row, $fields + ['cost_price' => null], auth()->id()), 'approved vendor invoice set this cost');

        Livewire::test('stock-ins.history')->call('openEdit', $row->id)->assertSee('approved vendor invoice set this cost');
    }

    public function test_a_note_only_edit_keeps_the_exact_billed_total(): void
    {
        $line = $this->one(3, 30.0);
        $this->approve($this->bill([[$line, 3, 100]]));

        // 33.33 × 3 would be 99.99.
        UpdateStockIn::handle($this->row($line), ['quantity' => 3, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => 'Shelf B', 'cost_price' => 33.33, 'selling_price' => 150.0], auth()->id());

        $this->assertSame('Shelf B', $this->row($line)->note);
        $this->assertSame(100.0, (float) $this->row($line)->total_cost);
    }

    public function test_an_invoice_approved_with_no_change_still_holds_the_cost(): void
    {
        $line = $this->one();
        $this->bill([[$line, 10, 1000]]);

        $this->assertRefused(fn () => UpdateStockIn::handle($this->row($line), ['quantity' => 10, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 200.0, 'selling_price' => 150.0], auth()->id()), 'approved vendor invoice set this cost');
    }

    public function test_after_cancelling_the_invoice_the_cost_can_be_edited_again(): void
    {
        $line = $this->one();
        VendorInvoices::cancel($this->bill([[$line, 10, 1000]]), auth()->id());

        UpdateStockIn::handle($this->row($line), ['quantity' => 10, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 200.0, 'selling_price' => 150.0], auth()->id());

        $this->assertSame(2000.0, (float) $this->row($line)->total_cost);
    }

    public function test_a_receipt_on_an_active_invoice_cannot_be_cancelled(): void
    {
        $line = $this->one();
        $grn = $this->row($line)->grnLine->grn;
        $invoice = $this->bill([[$line, 10, 1500]]);

        $this->assertRefused(fn () => CancelGrn::handle($grn, auth()->id()), 'Cancel the invoice first');

        VendorInvoices::dispute($invoice, 'No', auth()->id());
        $this->assertRefused(fn () => CancelGrn::handle($grn, auth()->id()), 'Cancel the invoice first');

        VendorInvoices::cancel($invoice->fresh(), auth()->id());
        CancelGrn::handle($grn->fresh(), auth()->id());

        $this->assertSame(0, StockIn::count());
    }

    // ── Payment ────────────────────────────────────────────────────────

    public function test_paying_shares_across_batches_writes_normal_payment_rows_and_stores_nothing_on_the_invoice(): void
    {
        [$a, $b] = $this->received([['product' => $this->cable, 'quantity' => 10, 'cost' => 100.0], ['product' => $this->charger, 'quantity' => 10, 'cost' => 300.0]]);
        $invoice = $this->bill([[$a->id, 10, 1000], [$b->id, 10, 3000]]);

        VendorInvoices::pay($invoice, 2000, auth()->id());

        $this->assertSame(500.0, (float) $this->row($a->id)->amount_paid);
        $this->assertSame(1500.0, (float) $this->row($b->id)->amount_paid);
        $this->assertSame(2000.0, $this->vendor->fresh()->totalOwed());

        $rows = StockInPayment::orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(StockInPaymentSource::InvoicePayment, $rows->first()->source);
        $this->assertSame($invoice->id, $rows->first()->vendor_invoice_id);
        $this->assertArrayNotHasKey('amount_paid', $invoice->fresh()->getAttributes());

        VendorInvoices::pay($invoice->fresh(), 2000, auth()->id());

        $this->assertSame(PaymentStatus::Paid, $this->row($a->id)->payment_status);
        $this->assertSame(PaymentStatus::Paid, $this->row($b->id)->payment_status);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_payments_add_up_exactly_when_the_split_rounds(): void
    {
        $items = array_map(fn (string $name) => ['product' => $this->piece($this->theShop, ['name' => $name]), 'quantity' => 1, 'cost' => 100.0], ['A', 'B', 'C']);
        $lines = $this->received($items);
        $invoice = $this->bill(array_map(fn ($line) => [$line->id, 1, 100], $lines));

        VendorInvoices::pay($invoice, 100, auth()->id());

        $this->assertSame(100.0, round((float) StockInPayment::sum('amount'), 2));
        $this->assertSame(200.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_paying_is_capped_at_what_is_owed(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1000]]);

        $this->assertRefused(fn () => VendorInvoices::pay($invoice, 1000.01, auth()->id()), 'Only Rs 1,000.00 is owed');
        $this->assertSame(0, StockInPayment::count());
    }

    public function test_only_an_approved_invoice_can_be_paid(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1500]]);

        $this->assertRefused(fn () => VendorInvoices::pay($invoice, 100, auth()->id()), 'Only an approved invoice');
    }

    public function test_paying_after_a_partial_payment_on_stock_in_history_sees_the_real_balance(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1000]]);

        Livewire::test('stock-ins.history')->call('openAddPayment', $this->row($line)->id)->set('paymentAmount', '400')->call('submitPayment')->assertHasNoErrors();

        $this->assertRefused(fn () => VendorInvoices::pay($invoice->fresh(), 601, auth()->id()), 'Only Rs 600.00');
        VendorInvoices::pay($invoice->fresh(), 600, auth()->id());

        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_stock_in_history_payments_still_work_as_before(): void
    {
        $line = $this->one();

        Livewire::test('stock-ins.history')->call('openAddPayment', $this->row($line)->id)->set('paymentAmount', '400')->call('submitPayment');

        $payment = StockInPayment::sole();
        $this->assertSame(StockInPaymentSource::Payment, $payment->source);
        $this->assertNull($payment->vendor_invoice_id);
        $this->assertSame(PaymentStatus::Partial, $this->row($line)->payment_status);
    }

    // ── Party Ledger ───────────────────────────────────────────────────

    public function test_the_statement_shows_the_correction_on_its_own_date_and_still_ends_at_what_is_owed(): void
    {
        $line = $this->one();
        $this->travel(5)->days();
        $invoice = $this->approve($this->bill([[$line, 10, 1200]]));

        $page = Livewire::withQueryParams(['party' => 'vendor', 'vendor' => $this->vendor->id])->test('party-ledger.index');

        $page->assertSee('Vendor invoice '.$invoice->number().': cost corrected');
        $this->assertSame(1200.0, $this->vendor->fresh()->totalOwed());
    }

    // ── Branches and access ────────────────────────────────────────────

    protected function staff(array $permissions, ?int $branchId = null): User
    {
        $role = Role::create(['shop_id' => $this->theShop->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);
        $user = User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $role->id, 'branch_id' => $branchId]);
        $this->actingAs($user);
        BranchContext::current()->forget();

        return $user;
    }

    public function test_branch_staff_approve_only_invoices_wholly_in_their_branch(): void
    {
        $second = Branch::create(['shop_id' => $this->theShop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $main = BranchContext::current()->mainBranchIdFor($this->theShop->id);

        $here = $this->received([['product' => $this->cable, 'quantity' => 1, 'cost' => 100.0]], $second->id)[0]->id;
        $there = $this->received([['product' => $this->charger, 'quantity' => 1, 'cost' => 100.0]], $main)[0]->id;
        $alsoHere = $this->received([['product' => $this->cable, 'quantity' => 1, 'cost' => 100.0]], $second->id)[0]->id;

        $spanning = $this->bill([[$here, 1, 200], [$there, 1, 200]], number: 'SPAN');
        $own = $this->bill([[$alsoHere, 1, 200]], number: 'OWN');

        $this->staff(['vendor-invoices-approve'], $second->id);

        $this->assertRefused(fn () => $this->approve($spanning), 'whole shop');
        $this->assertSame(200.0, (float) $this->approve($own)->lines->first()->stockIn->total_cost);

        Livewire::test('purchase-orders.invoice-show', ['invoice' => $spanning->id])
            ->assertSee('needs someone with access to the whole shop')
            ->assertDontSee('wire:click="openApprove"', false);
    }

    public function test_someone_with_shop_wide_access_can_approve_an_invoice_spanning_branches(): void
    {
        $second = Branch::create(['shop_id' => $this->theShop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $main = BranchContext::current()->mainBranchIdFor($this->theShop->id);
        $a = $this->received([['product' => $this->cable, 'quantity' => 1, 'cost' => 100.0]], $second->id)[0]->id;
        $b = $this->received([['product' => $this->charger, 'quantity' => 1, 'cost' => 100.0]], $main)[0]->id;

        $this->staff(['vendor-invoices-approve']);

        $this->assertTrue($this->approve($this->bill([[$a, 1, 200], [$b, 1, 200]]))->isApproved());
    }

    public function test_a_branch_clerk_can_only_bill_their_own_branchs_receipts(): void
    {
        $second = Branch::create(['shop_id' => $this->theShop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $main = BranchContext::current()->mainBranchIdFor($this->theShop->id);
        $there = $this->received([['product' => $this->charger, 'quantity' => 1, 'cost' => 100.0]], $main)[0]->id;

        $this->staff(['vendor-invoices'], $second->id);

        $this->assertRefused(fn () => $this->bill([[$there, 1, 100]]), 'another branch');
    }

    public function test_both_permissions_are_off_until_given_and_separate(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1500]]);
        $show = route('purchase-orders.invoices.show', $invoice->id);

        $this->get(route('purchase-orders.invoices'))->assertOk();
        $this->get(route('purchase-orders.invoices.create'))->assertOk();
        $this->get($show)->assertOk();

        $this->staff(['purchase-orders', 'stock-ins']);
        $this->get(route('purchase-orders.invoices'))->assertForbidden();
        $this->get(route('purchase-orders.invoices.create'))->assertForbidden();
        $this->get($show)->assertForbidden();

        $this->staff(['vendor-invoices']);
        $this->get(route('purchase-orders.invoices.create'))->assertOk();
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->assertDontSee('wire:click="openApprove"', false)->call('openApprove')->assertStatus(403);

        $this->staff(['vendor-invoices-approve']);
        $this->get(route('purchase-orders.invoices.create'))->assertForbidden();
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->assertSee('wire:click="openApprove"', false);
    }

    public function test_only_an_approver_can_undo_or_pay_an_approved_invoice(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1000]]);

        $this->staff(['vendor-invoices']);
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->call('cancel')->assertStatus(403);
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->call('openPay')->assertStatus(403);
        $this->assertTrue($invoice->fresh()->isApproved());
    }

    public function test_a_recording_clerk_can_cancel_their_mistake_before_approval(): void
    {
        $invoice = $this->bill([[$this->one(), 10, 1500]]);

        $this->staff(['vendor-invoices']);
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->call('cancel')->assertHasNoErrors();

        $this->assertTrue(VendorInvoice::withoutGlobalScope(ExcludesCancelledScope::class)->find($invoice->id)->isCancelled());
    }

    public function test_downgrade_keeps_invoices_readable_and_stops_new_ones_and_approval(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1500]]);
        $spare = $this->one();

        $this->theShop->update(['purchase_orders_enabled' => false]);
        auth()->user()->unsetRelation('shop');

        $this->assertRefused(fn () => $this->bill([[$spare, 10, 1000]], number: 'NEW'), "aren't on your plan");
        $this->assertRefused(fn () => $this->approve($invoice), "aren't on your plan");

        Livewire::test('purchase-orders.invoices')->assertSee($invoice->number())->assertSee("aren't on your plan", false);
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])->assertDontSee('wire:click="openApprove"', false);

        VendorInvoices::cancel($invoice, auth()->id());
        $this->assertSame(2000.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_another_shops_invoices_and_receipts_are_out_of_reach(): void
    {
        $mine = $this->one();
        $theirShop = Shop::create(['name' => 'Other', 'purchase_orders_enabled' => true]);
        $theirVendor = Vendor::create(['shop_id' => $theirShop->id, 'name' => 'TheirVendor']);
        $theirInvoice = VendorInvoice::withoutEvents(fn () => tap(new VendorInvoice(['vendor_id' => $theirVendor->id, 'invoice_number' => 'THEIRS', 'invoice_date' => now()->toDateString()]), function ($invoice) use ($theirShop) {
            $invoice->shop_id = $theirShop->id;
            $invoice->save();
        }));

        Livewire::test('purchase-orders.invoices')->assertDontSee('THEIRS');
        $this->assertRefused(fn () => $this->bill([[$mine, 10, 1000]], vendor: $theirVendor));

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('purchase-orders.invoice-show', ['invoice' => $theirInvoice->id]);
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_full_flow_through_the_screens(): void
    {
        $line = $this->one();

        $this->get(route('purchase-orders.index'))->assertSee('Vendor Invoices');

        $form = Livewire::test('purchase-orders.invoice-form')
            ->set('vendorId', (string) $this->vendor->id)
            ->assertSee('Cable')
            ->call('toggleLine', $line)
            ->assertSet("billed.{$line}.quantity", '10')
            ->assertSet("billed.{$line}.amount", '1000.00')
            ->assertSee('so it will be approved when you save')
            ->set("billed.{$line}.amount", '1200')
            ->assertSee('This will need review')
            ->assertSee('Rs 200.00')
            ->call('addCharge')
            ->set('charges.1.label', 'Delivery')
            ->set('charges.1.amount', '100')
            ->assertSee('Rs 1,300.00')
            ->call('save')
            ->assertHasErrors('invoiceNumber')
            ->set('invoiceNumber', 'BILL-77')
            ->call('save')
            ->assertHasNoErrors();

        $invoice = VendorInvoice::sole();
        $form->assertRedirect(route('purchase-orders.invoices.show', $invoice->id));
        $this->assertSame('BILL-77', $invoice->invoice_number);

        Livewire::test('purchase-orders.invoice-form')->set('vendorId', (string) $this->vendor->id)->assertDontSee('Cable');

        $show = Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])
            ->assertSee('This invoice needs review')
            ->call('openApprove')
            ->call('approve')
            ->assertHasErrors('reviewNote')
            ->set('reviewNote', 'Price went up')
            ->call('approve')
            ->assertHasNoErrors();

        $this->assertSame(1300.0, $this->vendor->fresh()->totalOwed());

        $show->assertSee('Cost corrected from Rs 1,000.00 to Rs 1,300.00')
            ->call('openPay')
            ->assertSet('payAmount', '1300')
            ->set('payAmount', '1400')
            ->call('pay')
            ->assertHasErrors('payAmount')
            ->set('payAmount', '1300')
            ->call('pay')
            ->assertHasNoErrors();

        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(PaymentStatus::Paid, $this->row($line)->payment_status);
    }

    public function test_dispute_and_cancel_through_the_screen(): void
    {
        $line = $this->one();
        $invoice = $this->bill([[$line, 10, 1500]]);

        Livewire::test('purchase-orders.invoice-show', ['invoice' => $invoice->id])
            ->call('openDispute')
            ->call('dispute')
            ->assertHasErrors('reviewNote')
            ->set('reviewNote', 'Overcharged')
            ->call('dispute')
            ->assertHasNoErrors()
            ->assertSee('Overcharged')
            ->assertSee('Disputed')
            ->call('openCancel')
            ->call('cancel')
            ->assertHasNoErrors();

        $this->assertSame(1000.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_the_list_filters_and_exports_consistently(): void
    {
        $a = $this->bill([[$this->one(), 10, 1000]], number: 'CLEAN');
        $this->bill([[$this->one(), 10, 1500]], number: 'DIFF');

        Livewire::test('purchase-orders.invoices')
            ->assertSee('CLEAN')->assertSee('DIFF')
            ->set('statusFilter', 'flagged')
            ->assertDontSee('CLEAN')->assertSee('DIFF');

        $report = new VendorInvoiceReport;
        $rows = $report->tableRows(forExcel: true);
        $this->assertCount(2, $rows);
        $this->assertCount(count($report->tableHeaders()), $rows[0]);
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());
        $this->assertSame('1', collect($report->summaryCards())->firstWhere('label', 'Needs Review')['value']);
        $this->assertSame('Rs 1,000.00', collect($report->summaryCards())->firstWhere('label', 'Owed on Approved')['value']);
    }

    public function test_the_match_is_the_one_shared_method_and_uses_whole_paise(): void
    {
        $this->assertSame(100, VendorInvoiceMatch::TOLERANCE_PAISE);
        $this->assertSame(10, VendorInvoiceMatch::paise(0.1));
        $this->assertSame(30, VendorInvoiceMatch::paise(0.1 + 0.2));

        $source = file_get_contents(app_path('Actions/VendorInvoices.php'));
        $this->assertStringNotContainsString('UpdateStockIn::', $source);
        $this->assertSame(1, substr_count(file_get_contents(app_path('Support/VendorInvoiceMatch.php')), 'public static function evaluate('));
    }
}
