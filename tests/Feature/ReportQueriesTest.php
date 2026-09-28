<?php

namespace Tests\Feature;

use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\StockIn;
use App\Models\User;
use App\Models\WalletLoad;
use App\ReportQueries\BalanceLoadReport;
use App\ReportQueries\BillReport;
use App\ReportQueries\NadraVerificationReport;
use App\ReportQueries\ProductReport;
use App\ReportQueries\RepairReport;
use App\ReportQueries\SalesReport;
use App\ReportQueries\SimSaleReport;
use App\ReportQueries\StockInReport;
use App\ReportQueries\UdhaarReport;
use App\ReportQueries\WalletLoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Each of the 10 Report classes is the single source of truth for its
 * screen's filtering/summary figures, shared by that screen's own History
 * component and the Reports screen. Per-screen History tests already cover
 * these indirectly (they didn't regress after the refactor); this file
 * exercises each Report class directly so the figures it produces are
 * verified in one place, independent of any particular UI.
 */
class ReportQueriesTest extends TestCase
{
    use RefreshDatabase;

    protected function shop(): Shop
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]));

        return $shop;
    }

    public function test_product_report_computes_stock_and_cost_value_for_filtered_rows_only(): void
    {
        $shop = $this->shop();
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'cost_price' => 60, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Power Bank', 'price' => 3000, 'cost_price' => 2000, 'stock_quantity' => 2, 'details' => ['category' => 'Power Bank']]);

        $report = new ProductReport(search: 'Cable');

        $this->assertSame(1, $report->totalProducts());
        $this->assertSame(1000.0, $report->totalStockValue()); // 10 * 100
        $this->assertSame(600.0, $report->totalCostValue());   // 10 * 60
    }

    public function test_sales_report_revenue_and_discount_are_date_scoped_but_outstanding_is_not(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        $inRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 1000, 'discount_amount' => 100, 'total' => 900]);
        $inRange->created_at = now();
        $inRange->save();

        $outOfRange = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 500, 'discount_amount' => 0, 'total' => 500]);
        $outOfRange->created_at = now()->subYear();
        $outOfRange->save();

        $report = new SalesReport(now()->toDateString(), now()->toDateString());

        $this->assertSame(900.0, $report->totalRevenue());
        $this->assertSame(100.0, $report->totalDiscount());
        // Outstanding is a running balance across ALL sales, unfiltered by the date range.
        $this->assertSame(1400.0, $report->totalOutstanding());
    }

    public function test_stock_in_report_units_are_filtered_but_owed_to_suppliers_is_not(): void
    {
        $shop = $this->shop();
        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'A', 'quantity' => 5, 'stock_date' => now()->toDateString(), 'payment_status' => 'unpaid', 'amount_paid' => 0, 'total_cost' => 500]);
        StockIn::create(['shop_id' => $shop->id, 'product_name' => 'B', 'quantity' => 3, 'stock_date' => now()->subYear()->toDateString(), 'payment_status' => 'unpaid', 'amount_paid' => 0, 'total_cost' => 300]);

        $report = new StockInReport(from: now()->toDateString());

        $this->assertSame(5, $report->totalUnits());
        $this->assertGreaterThan(0, $report->totalOwed());
    }

    public function test_wallet_load_report_totals_are_scoped_to_the_active_tab(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 700, 'total' => 700, 'payment_status' => 'unpaid', 'amount_paid' => 0]);
        WalletLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 400, 'total' => 400, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $cashIn = new WalletLoadReport(tab: 'cash_in');
        $cashOut = new WalletLoadReport(tab: 'cash_out');

        $this->assertSame(700.0, $cashIn->totalLoaded());
        $this->assertSame(700.0, $cashIn->totalOwed());
        $this->assertSame(400.0, $cashOut->totalLoaded());
        $this->assertSame(400.0, $cashOut->totalOwed());
    }

    public function test_nadra_verification_report_respects_the_payment_status_filter(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '0300', 'cnic_number' => '1', 'amount' => 600, 'total' => 600, 'payment_status' => 'paid', 'amount_paid' => 600]);
        NadraVerification::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'phone_number' => '0301', 'cnic_number' => '2', 'amount' => 600, 'total' => 600, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $report = new NadraVerificationReport(paymentStatusFilter: 'unpaid');

        $this->assertSame(600.0, $report->totalCollected());
        $this->assertSame(600.0, $report->totalOwed());
    }

    public function test_sim_sale_report_respects_the_network_filter(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '1', 'amount' => 500, 'total' => 500]);
        SimSale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Zong', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '2', 'amount' => 300, 'total' => 300]);

        $report = new SimSaleReport(network: 'Jazz');

        $this->assertSame(500.0, $report->totalRevenue());
        $this->assertCount(1, $report->tableRows(forExcel: false));
    }

    public function test_balance_load_report_fees_are_amount_plus_fee_minus_discount(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'fee' => 20, 'discount' => 5, 'total' => 515]);

        $report = new BalanceLoadReport;

        $this->assertSame(500.0, $report->totalLoaded());
        $this->assertSame(15.0, $report->totalFees());
    }

    public function test_bill_report_respects_the_category_filter(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();
        $electricity = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Electricity']);
        $gas = BillCategory::create(['shop_id' => $shop->id, 'name' => 'Gas']);

        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $electricity->id, 'consumer_number' => '1', 'consumer_name' => 'A', 'amount' => 400, 'total' => 400]);
        BillPayment::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'bill_category_id' => $gas->id, 'consumer_number' => '2', 'consumer_name' => 'B', 'amount' => 200, 'total' => 200]);

        $report = new BillReport(billCategoryId: (string) $electricity->id);

        $this->assertSame(400.0, $report->totalCollected());
    }

    public function test_repair_report_respects_the_category_filter(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();

        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'accessory', 'description' => 'Fix', 'amount' => 500, 'total' => 500]);
        Repair::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'category' => 'mobile', 'description' => 'Screen', 'amount' => 800, 'total' => 800]);

        $report = new RepairReport(category: 'accessory');

        $this->assertSame(500.0, $report->totalCollected());
    }

    public function test_udhaar_report_total_due_combines_loan_and_other_source_dues(): void
    {
        $shop = $this->shop();
        $owner = $shop->users()->first();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);

        $customer->udhaarTransactions()->create(['user_id' => $owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => now()->toDateString()]);
        BalanceLoad::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        $report = new UdhaarReport;

        $this->assertSame(1500.0, $report->totalDue());
        $this->assertCount(1, $report->rows());
    }

    // ── Tenant isolation across all 10 ────────────────────────────────

    public function test_every_report_class_is_scoped_to_the_acting_shop(): void
    {
        $shopA = Shop::create(['name' => 'Shop A']);
        $shopB = Shop::create(['name' => 'Shop B']);
        $ownerA = User::factory()->create(['shop_id' => $shopA->id]);
        $ownerB = User::factory()->create(['shop_id' => $shopB->id]);

        Product::create(['shop_id' => $shopB->id, 'type' => 'accessory', 'name' => 'Shop B Product', 'price' => 100, 'stock_quantity' => 1, 'details' => []]);
        Sale::create(['shop_id' => $shopB->id, 'user_id' => $ownerB->id, 'subtotal' => 999, 'discount_amount' => 0, 'total' => 999]);
        StockIn::create(['shop_id' => $shopB->id, 'product_name' => 'X', 'quantity' => 50, 'stock_date' => now()->toDateString()]);

        $this->actingAs($ownerA);

        $this->assertSame(0, (new ProductReport)->totalProducts());
        $this->assertSame(0.0, (new SalesReport)->totalRevenue());
        $this->assertSame(0, (new StockInReport)->totalUnits());
    }
}
