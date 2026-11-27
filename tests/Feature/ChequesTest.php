<?php

namespace Tests\Feature;

use App\Actions\Cheques;
use App\Actions\VendorCredits;
use App\Enums\ChequeStatus;
use App\Models\Cheque;
use App\ReportQueries\StockInReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * Cheques (W6): nothing is settled until a cheque clears; a bounce after
 * clearing brings the dues (or the vendor's balance) back.
 */
class ChequesTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_a_received_cheque_settles_nothing_until_it_clears_and_a_bounce_brings_the_dues_back(): void
    {
        $this->stock(50);
        $customer = $this->customer();
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-11-01']);
        $older = $this->sell($customer, 2, 0, '2026-11-02');      // 3,000 owed
        $newer = $this->sell($customer, 3, 500, '2026-11-10');    // 4,000 owed
        $flowBefore = $this->netMoneyMoved();

        $cheque = Cheques::record(['direction' => 'received', 'party_id' => $customer->id, 'cheque_number' => '004512', 'bank' => 'HBL', 'amount' => 5000, 'cheque_date' => '2026-11-30', 'received_on' => '2026-11-20'], $this->owner->id);
        $this->assertSame(1000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(7000.0, $customer->fresh()->salesOutstandingBalance());
        $this->assertSame(5000.0, Cheques::inHand($customer->id));

        Cheques::deposit($cheque, $this->bank->id);
        Cheques::clear($cheque->fresh(), null, $this->owner->id);

        $this->assertSame(ChequeStatus::Cleared, $cheque->fresh()->status);
        $this->assertSame(0.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(0.0, $older->fresh()->amountDue());
        $this->assertSame(3000.0, $newer->fresh()->amountDue());
        $this->assertSame(round($flowBefore + 5000, 2), $this->netMoneyMoved());
        $this->assertSame(0.0, Cheques::inHand($customer->id));

        Carbon::setTestNow('2026-12-02 08:00:00');
        Cheques::bounce($cheque->fresh(), 'Insufficient funds', $this->owner->id);

        $this->assertSame(ChequeStatus::Bounced, $cheque->fresh()->status);
        $this->assertSame(1000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(3000.0, $older->fresh()->amountDue());
        $this->assertSame(4000.0, $newer->fresh()->amountDue());
        $this->assertSame($flowBefore, $this->netMoneyMoved());
        $this->assertNotNull($cheque->fresh()->receipt->reversed_at);

        $this->expectException(ValidationException::class);
        Cheques::bounce($cheque->fresh(), 'again', $this->owner->id);
    }

    public function test_a_bounce_before_clearing_changes_nothing_and_counter_dues_are_left_alone(): void
    {
        $this->stock(10);
        $customer = $this->customer();
        $sale = $this->sell($customer, 1, 0);
        $cheque = Cheques::record(['direction' => 'received', 'party_id' => $customer->id, 'cheque_number' => '1', 'bank' => 'MCB', 'amount' => 1500, 'cheque_date' => '2026-11-20', 'received_on' => '2026-11-20'], $this->owner->id);

        Cheques::bounce($cheque, 'Signature differs', $this->owner->id);

        $this->assertSame(1500.0, $sale->fresh()->amountDue());
        $this->assertNull($cheque->fresh()->customer_receipt_id);
    }

    public function test_an_issued_cheque_pays_the_vendor_when_it_clears_and_any_extra_stays_as_credit(): void
    {
        $first = $this->stock(1, 1000, 'unpaid', '2026-10-01');
        $second = $this->stock(2, 1000, 'unpaid', '2026-10-05');
        $cheque = Cheques::record(['direction' => 'issued', 'party_id' => $this->vendor->id, 'cheque_number' => '7788', 'bank' => 'Meezan', 'amount' => 3500, 'cheque_date' => '2026-11-25', 'received_on' => '2026-11-20', 'shop_account_id' => $this->bank->id], $this->owner->id);
        $this->assertSame(3000.0, $this->vendor->totalOwed());

        Cheques::clear($cheque, null, $this->owner->id);

        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(500.0, VendorCredits::available($this->vendor->id));
        $this->assertSame(-3000.0, $this->netMoneyMoved());   // the 500 extra went to the vendor too; it's credit, not cash flow
        $this->assertSame(['paid', 'paid'], [$first->fresh()->payment_status->value, $second->fresh()->payment_status->value]);

        Cheques::bounce($cheque->fresh(), 'Stopped', $this->owner->id);
        $this->assertSame(3000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(0.0, VendorCredits::available($this->vendor->id));
        $this->assertSame(0.0, $this->netMoneyMoved());
        $this->assertSame((new StockInReport)->totalOwed(), $this->vendor->fresh()->totalOwed());
    }

    public function test_the_cheques_screen_records_and_clears(): void
    {
        $this->stock(10);
        $customer = $this->customer();
        $this->sell($customer, 2, 0);

        Livewire::test('cheques.index')
            ->call('openRecord')
            ->set('partyId', (string) $customer->id)->set('chequeNumber', '991')->set('bank', 'UBL')->set('amount', '3000')
            ->set('chequeDate', '2026-11-28')->set('receivedOn', '2026-11-20')
            ->call('record')->assertHasNoErrors()
            ->assertSee('991')->assertSee('In hand');

        $cheque = Cheque::sole();
        Livewire::test('cheques.index')->call('openAction', $cheque->id, 'clear')->set('shopAccountId', (string) $this->bank->id)->call('confirmAction')->assertHasNoErrors();

        $this->assertSame(0.0, $customer->fresh()->salesOutstandingBalance());
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Cheques in hand');
        $this->get(route('dues-aging.index'))->assertOk()->assertSee('Cheques in hand');
    }
}
