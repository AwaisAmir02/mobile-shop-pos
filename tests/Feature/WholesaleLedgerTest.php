<?php

namespace Tests\Feature;

use App\Actions\Cheques;
use App\Actions\Contra;
use App\Actions\PurchaseReturns;
use App\Actions\Schemes;
use App\Actions\StartLedger;
use App\Ledger\LedgerPoster;
use App\Models\Scheme;
use App\ReportQueries\LedgerHealthReport;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DistributionFixtures;
use Tests\TestCase;

/**
 * The double-entry ledger stays balanced and agrees with the records through
 * every Wholesale P2 flow.
 */
class WholesaleLedgerTest extends TestCase
{
    use DistributionFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    public function test_the_ledger_balances_and_agrees_with_the_records_through_every_p2_flow(): void
    {
        // Already started under LEDGER_FORCE_ON.
        $this->shop = $this->shop->fresh()->ledger_started_at ? $this->shop->fresh() : StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        $this->actingAs($this->owner->fresh());
        $customer = $this->customer(['vendor_id' => $this->vendor->id]);
        $paidBatch = $this->stock(20, 1000, 'paid', '2026-11-01');
        $this->stock(20, 1000, 'unpaid', '2026-11-02');
        $scheme = Scheme::create(['shop_id' => $this->shop->id, 'name' => '10+1', 'product_id' => $this->tea->id, 'vendor_id' => $this->vendor->id, 'buy_quantity' => 10, 'free_quantity' => 1, 'starts_on' => '2026-11-01']);
        $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);

        $cheque = Cheques::record(['direction' => 'received', 'party_id' => $customer->id, 'cheque_number' => '1', 'bank' => 'HBL', 'amount' => 4000, 'cheque_date' => '2026-11-20', 'received_on' => '2026-11-20'], $this->owner->id);
        Cheques::clear($cheque, $this->bank->id, $this->owner->id);
        $issued = Cheques::record(['direction' => 'issued', 'party_id' => $this->vendor->id, 'cheque_number' => '2', 'bank' => 'Meezan', 'amount' => 5000, 'cheque_date' => '2026-11-20', 'received_on' => '2026-11-20', 'shop_account_id' => $this->bank->id], $this->owner->id);
        Cheques::clear($issued, null, $this->owner->id);
        PurchaseReturns::create($this->vendor->id, [$paidBatch->id => 2], 'expired', null, '2026-11-20', $this->owner->id);
        Schemes::settle(Schemes::claim($this->vendor->id, null, null, null, $this->owner->id), 1000, 'credit', null, null, $this->owner->id);
        Contra::settle($customer->fresh(), 1000, null, $this->owner->id);
        Cheques::bounce($cheque->fresh(), 'Funds', $this->owner->id);

        app(LedgerPoster::class)->drain(limit: 100000);
        $this->assertNull(\App\Models\LedgerOutbox::whereNotNull('last_error')->value('last_error'));
        $this->assertSame(0, (int) \App\Models\LedgerLine::sum('amount_paise'));

        foreach ((new LedgerHealthReport($this->shop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'] ?? 0.0, abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }
}
