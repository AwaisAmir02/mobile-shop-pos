<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\RecordSalePayment;
use App\Actions\SaleReturns;
use App\Models\Sale;
use App\ReportQueries\UdhaarReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\WholesaleShopFixtures;
use Tests\TestCase;

/**
 * Sale::owing() (W4): loads only sales still owed, and every dues figure built
 * on it is the same as adding up every sale.
 */
class WholesaleDuesScopeTest extends TestCase
{
    use WholesaleShopFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openWholesaleShop();
    }

    public function test_the_owing_scope_loads_only_owed_sales_and_every_figure_stays_the_same(): void
    {
        $product = $this->product(['wholesale_price' => null, 'price' => 133.33]);
        $a = $this->customer(['name' => 'A']);
        $b = $this->customer(['name' => 'B']);

        $paid = $this->sell($a, $product, 3, 399.99);
        $partly = $this->sell($a, $product, 2, 100);
        $none = $this->sell($b, $product, 1, 0);
        $overpaidLater = $this->sell($b, $product, 1, 50);
        RecordSalePayment::handle($overpaidLater->fresh(), 83.33, $this->owner->id, 'cash');
        $returned = $this->sell($a, $product, 4, 0);
        SaleReturns::create($returned->fresh(), ['p'.$product->id => ['quantity' => '1', 'disposition' => 'restock']], 'cash', null, null, $this->owner->id, byOwner: true);
        $cancelled = $this->sell($b, $product, 2, 0);
        CancelSale::handle($cancelled->fresh(), $this->owner->id, 'x');
        $this->sell(null, $product, 1, 50);

        $old = Sale::query()->withSum('payments', 'amount')->withSum('returns', 'due_reduction')->get();
        $owing = Sale::query()->owing()->get();

        $this->assertEqualsCanonicalizing(
            $old->filter(fn (Sale $s) => $s->amountDue() > 0)->pluck('id')->all(),
            $owing->pluck('id')->all()
        );
        $this->assertNotContains($paid->id, $owing->pluck('id'));
        $this->assertNotContains($overpaidLater->id, $owing->pluck('id'));
        $this->assertContains($partly->id, $owing->pluck('id'));
        $this->assertContains($none->id, $owing->pluck('id'));
        $this->assertContains($returned->id, $owing->pluck('id'));
        $this->assertSame($old->sum(fn (Sale $s) => $s->amountDue()), $owing->sum(fn (Sale $s) => $s->amountDue()));

        foreach ([$a, $b] as $customer) {
            $expected = Sale::where('customer_id', $customer->id)->withSum('payments', 'amount')->withSum('returns', 'due_reduction')->get()->sum(fn (Sale $s) => $s->amountDue());
            $this->assertSame($expected, $customer->fresh()->salesOutstandingBalance());
        }

        $rows = (new UdhaarReport)->rows()->keyBy(fn ($row) => $row['customer']->id);
        // A: 266.66 - 100 paid, plus 533.32 less one returned (133.33).
        $this->assertSame(566.65, round($rows[$a->id]['balance'], 2));
        $this->assertSame(133.33, round($rows[$b->id]['balance'], 2));
    }
}
