<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Enums\QuantityUnit;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\ProfitReport;
use App\Services\ProductGroupService;
use App\Services\ShopReportService;
use App\Support\BranchContext;
use App\Support\Quantity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class ProfitReportTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected function today(): string
    {
        return now()->toDateString();
    }

    protected function report(): ProfitReport
    {
        return new ProfitReport($this->today(), $this->today());
    }

    protected function saleWithDiscount(array $lines, float $invoiceDiscount): Sale
    {
        return CreateSale::handle(
            lines: $lines,
            invoiceDiscount: $invoiceDiscount,
            customerId: null,
            userId: auth()->id(),
            saleDate: $this->today(),
            amountPaid: 0,
        );
    }

    public function test_gross_profit_is_sales_minus_the_batch_cost_of_what_was_sold(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 1, ['stock_date' => now()->subDays(3)->toDateString(), 'selling_price' => 300, 'cost_price' => 200]);
        $this->batch($product, 5, ['selling_price' => 320, 'cost_price' => 240]);

        // 1 kg at 300/200 and 1 kg at 320/240: a sale across two batches.
        $this->sell($product, 2);

        $totals = $this->report()->totals();

        $this->assertSame(620.0, $totals['revenue']);
        $this->assertSame(440.0, $totals['cogs']);
        $this->assertSame(180.0, $totals['grossProfit']);
        $this->assertSame(29.0, $totals['margin']);
        $this->assertSame(0, $totals['linesWithNoCost']);
        $this->assertNull($this->report()->noCostNotice());
    }

    public function test_fractional_lines_use_the_same_rounding_as_the_rest_of_the_app(): void
    {
        $product = $this->product($this->shop());
        $this->batch($product, 5, ['selling_price' => 100, 'cost_price' => 33.33]);

        $this->sell($product, 1.5);

        $this->assertSame(Quantity::money(33.33, 1.5), $this->report()->totals()['cogs']);
        $this->assertSame(50.0, $this->report()->totals()['cogs']);
    }

    public function test_discounts_come_off_so_profit_is_not_overstated(): void
    {
        $product = $this->piece($this->shop());
        $this->batch($product, 10, ['selling_price' => 100, 'cost_price' => 60]);

        // Line: 2 × 100 − 20 off = 180. Invoice: 30 off. Sold for 150, cost 120.
        $this->saleWithDiscount([['product_id' => $product->id, 'quantity' => 2, 'discount' => 20.0, 'custom_price' => null]], 30.0);

        $totals = $this->report()->totals();

        $this->assertSame(150.0, $totals['revenue']);
        $this->assertSame(120.0, $totals['cogs']);
        $this->assertSame(30.0, $totals['grossProfit']);
    }

    public function test_a_line_with_no_cost_is_left_out_of_profit_and_named_in_a_notice(): void
    {
        $shop = $this->shop();
        $costed = $this->piece($shop, ['name' => 'Costed', 'price' => 100]);
        $this->batch($costed, 5, ['selling_price' => 100, 'cost_price' => 60]);
        $uncosted = $this->piece($shop, ['name' => 'Uncosted', 'price' => 500, 'cost_price' => null, 'stock_quantity' => 5]);

        $this->sell($costed, 1);
        $this->sell($uncosted, 1);

        $this->assertNull(SaleItem::where('product_id', $uncosted->id)->first()->cost_price);

        $totals = $this->report()->totals();

        // Not 540 of profit, as treating the missing cost as zero would claim.
        $this->assertSame(40.0, $totals['grossProfit']);
        $this->assertSame(600.0, $totals['revenue']);
        $this->assertSame(100.0, $totals['costedRevenue']);
        $this->assertSame(500.0, $totals['uncostedRevenue']);
        $this->assertSame(1, $totals['linesWithNoCost']);
        $this->assertSame('1 sale line has no recorded cost — profit may be understated.', $this->report()->noCostNotice());

        Livewire::test('reports.profit')
            ->assertSee('1 sale line has no recorded cost — profit may be understated.')
            ->assertSee('Rs 500.00')
            ->assertSee('1 with no cost');
    }

    public function test_an_invoice_discount_is_shared_between_costed_and_uncosted_lines(): void
    {
        $shop = $this->shop();
        $costed = $this->piece($shop, ['name' => 'Costed']);
        $this->batch($costed, 5, ['selling_price' => 300, 'cost_price' => 100]);
        $uncosted = $this->piece($shop, ['name' => 'Uncosted', 'price' => 100, 'cost_price' => null, 'stock_quantity' => 5]);

        $this->saleWithDiscount([
            ['product_id' => $costed->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null],
            ['product_id' => $uncosted->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null],
        ], 40.0);

        $totals = $this->report()->totals();

        // 3/4 of the 40 belongs to the costed line: 300 − 30 − 100.
        $this->assertSame(170.0, $totals['grossProfit']);
        $this->assertSame(360.0, $totals['revenue']);
        $this->assertSame(90.0, $totals['uncostedRevenue']);
    }

    public function test_a_real_zero_cost_counts_as_a_cost(): void
    {
        $product = $this->piece($this->shop());
        $this->batch($product, 5, ['selling_price' => 100, 'cost_price' => 0]);

        $this->sell($product, 1);

        $this->assertSame(0, $this->report()->totals()['linesWithNoCost']);
        $this->assertSame(100.0, $this->report()->totals()['grossProfit']);
    }

    public function test_the_date_range_filters_by_sale_date_and_cancelled_sales_drop_out(): void
    {
        $product = $this->piece($this->shop());
        $this->batch($product, 10, ['selling_price' => 100, 'cost_price' => 60]);

        CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, auth()->id(), now()->subDays(40)->toDateString(), 0);
        $this->sell($product, 2);
        $cancelled = $this->sell($product, 3);
        CancelSale::handle($cancelled, auth()->id());

        $this->assertSame(80.0, $this->report()->totals()['grossProfit']);
        $this->assertSame(120.0, (new ProfitReport)->totals()['grossProfit']);
        $this->assertSame(40.0, (new ProfitReport(now()->subDays(41)->toDateString(), now()->subDays(39)->toDateString()))->totals()['grossProfit']);
    }

    public function test_sales_revenue_matches_the_dashboard_and_the_dashboard_net_is_untouched(): void
    {
        $shop = $this->shop();
        $product = $this->piece($shop);
        $this->batch($product, 10, ['selling_price' => 100, 'cost_price' => 60]);
        $this->saleWithDiscount([['product_id' => $product->id, 'quantity' => 3, 'discount' => 5.0, 'custom_price' => null]], 15.0);

        $summary = app(ShopReportService::class)->summary($shop, now()->startOfDay(), now()->endOfDay());
        $totals = $this->report()->totals();

        $this->assertSame($summary['totalRevenue'], $totals['revenue']);
        // Net is still revenue minus expenses, with no cost of goods in it.
        $this->assertSame($summary['totalRevenue'] - $summary['totalExpenses'], $summary['netSummary']);
        $this->assertNotSame($summary['netSummary'], $totals['grossProfit']);
        Livewire::test('dashboard.index')->assertSee('Net (Revenue − Expenses)')->assertDontSee('Gross Profit');
    }

    public function test_the_two_figures_are_told_apart_on_screen(): void
    {
        $this->shop();

        Livewire::test('reports.profit')
            ->assertSee('Gross Profit')
            ->assertSee('Cost of Goods Sold')
            ->assertSee('Net (Revenue − Expenses)')
            ->assertSee('subtracts expenses but not the cost of goods');
    }

    public function test_rows_are_per_product_with_their_units_and_exports_line_up(): void
    {
        $shop = $this->shop(['variants_enabled' => true]);
        $rice = $this->product($shop);
        $this->batch($rice, 5, ['selling_price' => 100, 'cost_price' => 60]);
        $service = app(ProductGroupService::class);
        [$red] = $service->addVariants($service->createGroup($shop->id, 'Shirt', 'grocery', ''), ['Red'], 500, null);
        $this->batch($red->fresh(), 5, ['selling_price' => 500, 'cost_price' => 300]);

        $this->sell($rice, 1.5);
        $this->sell($red->fresh(), 1);

        $report = $this->report();
        $rows = $report->byProduct();

        $this->assertSame(['Shirt - Red', 'Basmati Rice'], array_column($rows, 'name'));
        $this->assertSame(1.5, $rows[1]['quantity']);
        $this->assertSame(QuantityUnit::Kg, $rows[1]['unit']);
        $this->assertSame(60.0, $rows[1]['profit']);

        $excel = $report->tableRows(forExcel: true);
        $this->assertCount(count($report->tableHeaders()), $excel[0]);
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());
        $this->assertSame(1.5, $excel[1][1]);
        $this->assertSame('Date range: '.now()->format('d M Y').' – '.now()->format('d M Y'), $report->filtersSummary());

        Livewire::test('reports.profit')->assertSee('1.5 kg')->assertSee('Shirt - Red');
    }

    public function test_it_follows_the_branch_switcher_like_other_reports(): void
    {
        $shop = $this->shop();
        $product = $this->piece($shop);
        $this->batch($product, 10, ['selling_price' => 100, 'cost_price' => 60]);
        $this->sell($product, 1);
        $elsewhere = $this->sell($product, 2);

        $other = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        Sale::whereKey($elsewhere->id)->update(['branch_id' => $other->id]);

        $this->assertSame(120.0, $this->report()->totals()['grossProfit']);
        $this->assertSame(80.0, BranchContext::current()->forBranch($other->id, fn () => $this->report()->totals()['grossProfit']));
        $this->assertSame(['Cable'], BranchContext::current()->forBranch($other->id, fn () => array_column($this->report()->byProduct(), 'name')));
    }

    public function test_it_needs_both_reports_and_cost_access_and_no_plan(): void
    {
        $shop = $this->shop();
        $this->assertNull($shop->plan_id);

        $this->get(route('profit.index'))->assertOk();

        $noCost = Role::create(['shop_id' => $shop->id, 'name' => 'No cost', 'permissions' => ['reports']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $noCost->id]));
        $this->get(route('profit.index'))->assertForbidden();

        $noReports = Role::create(['shop_id' => $shop->id, 'name' => 'No reports', 'permissions' => ['sales-view-cost-price']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $noReports->id]));
        $this->get(route('profit.index'))->assertForbidden();
    }

    public function test_another_shops_sales_are_not_counted(): void
    {
        $other = Shop::create(['name' => 'Other']);
        $theirs = Product::create(['shop_id' => $other->id, 'type' => 'accessory', 'name' => 'Theirs', 'price' => 900, 'cost_price' => 100, 'stock_quantity' => 5, 'details' => []]);
        CreateSale::handle([['product_id' => $theirs->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, null, null, $this->today(), 0, $other->id);

        $this->shop();

        $this->assertSame(0.0, $this->report()->totals()['revenue']);
        $this->assertSame([], $this->report()->byProduct());
    }

    public function test_reading_the_report_writes_nothing(): void
    {
        $product = $this->piece($this->shop());
        $this->batch($product, 5, ['selling_price' => 100, 'cost_price' => 60]);
        $this->sell($product, 1);

        $writes = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes) {
            if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
                $writes[] = $query->sql;
            }
        });

        $report = $this->report();
        $report->totals();
        $report->byProduct();
        $report->tableRows(forExcel: true);

        $this->assertSame([], $writes);
    }
}
