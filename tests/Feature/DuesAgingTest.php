<?php

namespace Tests\Feature;

use App\ReportQueries\DuesAging;
use App\ReportQueries\UdhaarReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Dues aging (W5, DuesAging): bills and udhaar aged separately, agreeing with
 * the Udhaar list.
 */
class DuesAgingTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_aging_buckets_bills_and_udhaar_separately_and_agrees_with_the_udhaar_list(): void
    {
        $product = $this->product(['wholesale_price' => null]);
        $customer = $this->customer(['area' => 'Shah Alam']);
        $this->sell($customer, $product, 1, 0, '2026-11-10', ['due_date' => '2026-11-15']);   // 10 days, overdue
        $this->sell($customer, $product, 2, 0, '2026-10-01');                                 // 50 days
        $this->sell($customer, $product, 3, 100, '2026-09-01');                               // 80 days, 200 owed
        $this->sell($customer, $product, 4, 0, '2026-06-01');                                 // 172 days
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 300, 'transaction_date' => '2026-07-01']);
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 700, 'transaction_date' => '2026-11-01']);
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'repayment', 'amount' => 400, 'transaction_date' => '2026-11-10']);

        $row = DuesAging::today()->forCustomer($customer);

        $this->assertSame([100.0, 200.0, 200.0, 400.0], $row['bills']['buckets']);
        $this->assertSame(900.0, $row['bills']['total']);
        $this->assertSame(100.0, $row['bills']['overdue']);
        // The repayment paid the July loan first; 600 of November's is left.
        $this->assertSame([600.0, 0.0, 0.0, 0.0], $row['udhaar']['buckets']);
        $this->assertSame(600.0, $row['udhaar']['total']);

        $listed = (new UdhaarReport)->rows()->firstWhere('customer.id', $customer->id)['balance'];
        $this->assertSame(round($listed, 2), round($row['bills']['total'] + $row['udhaar']['total'], 2));

        $this->get(route('dues-aging.index'))->assertOk()->assertSee('Bilal General Store (Bilal)')->assertSee('Rs 900.00');
        $this->get(route('dues-aging.index', ['part' => 'udhaar']))->assertOk()->assertSee('Rs 600.00');
        $this->get(route('dues-aging.index', ['area' => 'Elsewhere']))->assertOk()->assertSee('Nothing matches these filters.');
        $this->get(route('reports.index'))->assertSee('Dues aging');
    }
}
