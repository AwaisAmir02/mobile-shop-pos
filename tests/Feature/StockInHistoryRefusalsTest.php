<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\ReceiveGoods;
use App\Actions\RecordStockInPayment;
use App\Actions\SavePurchaseOrder;
use App\Models\StockIn;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

/** A refused edit or cancel must say why, not look like nothing happened. */
class StockInHistoryRefusalsTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    public function test_a_refused_quantity_edit_shows_why_and_changes_nothing(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $this->sell($product, 6);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->set('editQuantity', '3')
            ->call('saveEdit')
            ->assertHasErrors('quantity')
            ->assertSeeHtml('Cannot reduce quantity: 6 units from this entry have already been sold')
            ->assertNotDispatched('close-modal');

        $this->assertSame(10, $batch->fresh()->quantity);
    }

    public function test_a_retry_that_works_clears_the_message_and_closes_the_form(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $this->sell($product, 6);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->set('editQuantity', '3')
            ->call('saveEdit')
            ->assertHasErrors('quantity')
            ->set('editQuantity', '8')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertDontSeeHtml('Cannot reduce quantity')
            ->assertDispatched('close-modal', name: 'edit-stock-in-form');

        $this->assertSame(8, $batch->fresh()->quantity);
    }

    public function test_a_cost_below_what_is_paid_shows_why(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        RecordStockInPayment::add($batch, 2000, auth()->id());

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->set('editCostPrice', '100')
            ->call('saveEdit')
            ->assertHasErrors('quantity')
            ->assertSeeHtml('is less than Rs 2,000.00 already paid');

        $this->assertSame(2500.0, (float) $batch->fresh()->total_cost);
    }

    public function test_reopening_the_edit_form_clears_an_old_message(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $this->sell($product, 6);

        Livewire::test('stock-ins.history')
            ->call('openEdit', $batch->id)
            ->set('editQuantity', '3')
            ->call('saveEdit')
            ->call('closeEditForm')
            ->call('openEdit', $batch->id)
            ->assertHasNoErrors()
            ->assertDontSeeHtml('Cannot reduce quantity');
    }

    public function test_a_refused_cancel_shows_why_and_changes_nothing(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $this->sell($product, 6);

        Livewire::test('stock-ins.history')
            ->call('openCancel', $batch->id)
            ->call('cancelEntry')
            ->assertHasErrors('quantity')
            ->assertSeeHtml('Cannot cancel: 6 of the 10 units added by this entry have already been sold')
            ->assertNotDispatched('close-modal');

        $this->assertFalse($batch->fresh()->isCancelled());
        $this->assertSame(4, $product->fresh()->stock_quantity);
    }

    public function test_a_cancel_retry_that_works_clears_the_message(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $sale = $this->sell($product, 6);

        $page = Livewire::test('stock-ins.history')
            ->call('openCancel', $batch->id)
            ->call('cancelEntry')
            ->assertHasErrors('quantity');

        CancelSale::handle($sale, auth()->id());

        $page->call('cancelEntry')
            ->assertHasNoErrors()
            ->assertDontSeeHtml('Cannot cancel')
            ->assertDispatched('close-modal', name: 'cancel-stock-in-form');

        $this->assertSame(0, StockIn::count());
    }

    public function test_reopening_the_cancel_form_clears_an_old_message(): void
    {
        $product = $this->piece($this->shop());
        $batch = $this->batch($product, 10);
        $this->sell($product, 6);

        Livewire::test('stock-ins.history')
            ->call('openCancel', $batch->id)
            ->call('cancelEntry')
            ->assertHasErrors('quantity')
            ->call('closeCancelForm')
            ->call('openCancel', $batch->id)
            ->assertHasNoErrors()
            ->assertDontSeeHtml('Cannot cancel');
    }

    public function test_the_expiry_screen_shows_why_a_date_was_refused(): void
    {
        $product = $this->piece($this->shop(['expiry_tracking_enabled' => true]));
        $batch = $this->batch($product, 10);
        // Overpaid, so UpdateStockIn refuses any edit to it.
        RecordStockInPayment::add($batch, 3000, auth()->id());

        Livewire::test('stock-ins.expiry')
            ->call('openDate', $batch->id)
            ->set('datingDate', now()->addMonth()->toDateString())
            ->call('saveDate')
            ->assertHasErrors('quantity')
            ->assertSeeHtml('is less than Rs 3,000.00 already paid')
            ->assertNotDispatched('close-modal');

        $this->assertNull($batch->fresh()->expiry_date);
    }

    public function test_the_expiry_screen_still_saves_a_date_normally(): void
    {
        $product = $this->piece($this->shop(['expiry_tracking_enabled' => true]));
        $batch = $this->batch($product, 10);
        $date = now()->addMonth()->toDateString();

        Livewire::test('stock-ins.expiry')
            ->call('openDate', $batch->id)
            ->set('datingDate', $date)
            ->call('saveDate')
            ->assertHasNoErrors()
            ->assertDispatched('close-modal', name: 'batch-expiry-form');

        $this->assertSame($date, $batch->fresh()->expiry_date->toDateString());
    }

    public function test_a_forged_cancel_of_a_received_batch_shows_why(): void
    {
        $shop = $this->shop(['purchase_orders_enabled' => true]);
        $product = $this->piece($shop);
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Acme']);
        $order = SavePurchaseOrder::handle(null, ['vendor_id' => $vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $product->id, 'quantity' => 5, 'expected_cost' => 100.0]], auth()->id());
        ReceiveGoods::handle($order->fresh(), [['po_line_id' => $order->lines->first()->id, 'quantity' => 5, 'cost_price' => 100.0, 'selling_price' => 150.0]], now()->toDateString(), null, null, auth()->id());

        Livewire::test('stock-ins.history')
            ->call('openCancel', StockIn::sole()->id)
            ->call('cancelEntry')
            ->assertHasErrors('quantity')
            ->assertSeeHtml('This stock came from a goods receipt. Cancel the receipt instead.');
    }
}
