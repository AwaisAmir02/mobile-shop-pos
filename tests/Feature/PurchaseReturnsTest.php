<?php

namespace Tests\Feature;

use App\Actions\PurchaseReturns;
use App\Actions\VendorCredits;
use App\ReportQueries\StockInReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Purchase returns (W10): stock out of its own batch, and a debit note that
 * comes off what is owed, or stays as credit.
 */
class PurchaseReturnsTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_a_purchase_return_leaves_stock_from_its_batch_and_comes_off_what_is_owed(): void
    {
        $paid = $this->stock(10, 1000, 'paid', '2026-10-01');
        $unpaid = $this->stock(10, 1000, 'unpaid', '2026-10-05');
        $this->assertSame(10000.0, $this->vendor->totalOwed());

        $return = PurchaseReturns::create($this->vendor->id, [$paid->id => 4], 'expired', null, '2026-11-20', $this->owner->id);

        $this->assertSame('4000.00', $return->total_value);
        $this->assertSame(6, $paid->fresh()->quantity_remaining);
        $this->assertSame(16, $this->tea->fresh()->stock_quantity);
        // The returned batch was paid, so the debit note comes off the unpaid one.
        $this->assertSame(6000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame((new StockInReport)->totalOwed(), $this->vendor->fresh()->totalOwed());
        $this->assertSame(-10000.0, $this->netMoneyMoved());   // only the first batch's payment: the return moved no money

        PurchaseReturns::cancel($return, $this->owner->id);
        $this->assertSame(10, $paid->fresh()->quantity_remaining);
        $this->assertSame(20, $this->tea->fresh()->stock_quantity);
        $this->assertSame(10000.0, $this->vendor->fresh()->totalOwed());
    }

    public function test_more_returned_than_owed_stays_as_credit_to_refund_or_take_off_later_stock(): void
    {
        $batch = $this->stock(10, 1000, 'paid');
        $return = PurchaseReturns::create($this->vendor->id, [$batch->id => 3], 'damaged', null, '2026-11-20', $this->owner->id);
        $credit = $return->credit();
        $this->assertSame('3000.00', $credit->remaining);

        VendorCredits::refund($credit, 1000, 'cash', null, $this->owner->id);
        $this->assertSame(round(-10000 + 1000, 2), $this->netMoneyMoved());

        $this->stock(5, 1000, 'unpaid');
        $this->assertSame(2000.0, VendorCredits::applyToStock($credit->fresh(), $this->owner->id));
        $this->assertSame(3000.0, $this->vendor->fresh()->totalOwed());

        $this->expectException(ValidationException::class);
        PurchaseReturns::cancel($return, $this->owner->id);   // already part refunded
    }

    public function test_the_return_screen_saves_a_debit_note(): void
    {
        $batch = $this->stock(10, 1000, 'unpaid');

        Livewire::test('purchase-returns.create')->set('vendorId', (string) $this->vendor->id)->set("quantities.{$batch->id}", '2')
            ->assertSee('Rs 2,000.00')->call('save')->assertHasNoErrors();

        $this->assertSame(8000.0, $this->vendor->fresh()->totalOwed());
        $html = view('pdf.debit-note', ['return' => \App\Models\PurchaseReturn::with(['items', 'vendor', 'user'])->sole(), 'shop' => $this->shop, 'credit' => \App\Models\PurchaseReturn::sole()->credit()])->render();
        $this->assertStringContainsString('Debit Note', $html);
        $this->get(route('vendors.show', $this->vendor))->assertOk();
    }
}
