<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One test per call site enumerated during the Prompt 3 investigation that
 * reads/sums Sale data — every one of the 14 must exclude a cancelled sale,
 * with no exceptions relied on "obviously" inheriting the global scope.
 */
class SaleCancellationExclusionTest extends TestCase
{
    use RefreshDatabase;

    protected function shopOwner(): array
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);

        return [$shop, $owner];
    }

    protected function cancelledSale(int $shopId, int $userId, ?int $customerId = null, float $total = 12345.0): Sale
    {
        $sale = Sale::create(['shop_id' => $shopId, 'user_id' => $userId, 'customer_id' => $customerId, 'subtotal' => $total, 'discount_amount' => 0, 'total' => $total]);
        SaleItem::create(['shop_id' => $shopId, 'sale_id' => $sale->id, 'product_id' => null, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => $total, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => $total]);
        CancelSale::handle($sale, $userId, null);

        return $sale->fresh();
    }

    // 1. ShopReportService::summary() total revenue
    public function test_1_shop_report_service_summary_excludes_cancelled_revenue(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        $summary = app(ShopReportService::class)->summary($shop->fresh(), now()->subDay(), now()->addDay());

        $this->assertSame(0.0, $summary['totalRevenue']);
    }

    // 2. ShopReportService::revenueTrend() dashboard chart
    public function test_2_shop_report_service_revenue_trend_excludes_cancelled_sales(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        $trend = app(ShopReportService::class)->revenueTrend($shop->fresh(), 'day');

        $this->assertSame(0.0, array_sum(array_column($trend, 'value')));
    }

    // 3. dashboard/index.blade.php::with() totalSalesOutstanding
    public function test_3_dashboard_total_sales_outstanding_excludes_cancelled_sales(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        Livewire::test('dashboard.index')->assertViewHas('totalSalesOutstanding', 0.0);
    }

    // 4. SalesReport::totalRevenue()
    public function test_4_sales_report_total_revenue_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        $report = new \App\ReportQueries\SalesReport;
        $this->assertSame(0.0, $report->totalRevenue());
    }

    // 4b. SalesReport::totalDiscount()
    public function test_4b_sales_report_total_discount_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'subtotal' => 1000, 'discount_amount' => 200, 'total' => 800]);
        SaleItem::create(['shop_id' => $shop->id, 'sale_id' => $sale->id, 'product_name' => 'X', 'product_type' => 'accessory', 'unit_price' => 1000, 'quantity' => 1, 'discount_amount' => 0, 'line_total' => 1000]);
        CancelSale::handle($sale, $owner->id, null);

        $report = new \App\ReportQueries\SalesReport;
        $this->assertSame(0.0, $report->totalDiscount());
    }

    // 4c. SalesReport::totalOutstanding()
    public function test_4c_sales_report_total_outstanding_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        $report = new \App\ReportQueries\SalesReport;
        $this->assertSame(0.0, $report->totalOutstanding());
    }

    // 5. Sales History totalOutstanding card (the live screen, not just the report class)
    public function test_5_sales_history_total_outstanding_card_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $this->cancelledSale($shop->id, $owner->id);

        Livewire::test('sales.history')->assertViewHas('totalOutstanding', 0.0);
    }

    // 7. Party Ledger statement: a cancelled sale never appears as an entry
    public function test_7_party_ledger_statement_never_lists_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $sale = $this->cancelledSale($shop->id, $owner->id, $customer->id);

        Livewire::test('party-ledger.index')
            ->set('customerId', $customer->id)
            ->assertViewHas('statement', fn ($statement) => $statement['sales']['rows']->isEmpty())
            ->assertDontSee($sale->invoiceNumber());
    }

    // 7b. Party Ledger statement: per-customer Sales closing balance
    public function test_7b_party_ledger_sales_closing_balance_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->cancelledSale($shop->id, $owner->id, $customer->id);

        Livewire::test('party-ledger.index')
            ->set('customerId', $customer->id)
            ->assertViewHas('statement', fn ($statement) => $statement['sales']['closing'] === 0.0);
    }

    // 7c. Party Ledger statement: payments on a cancelled sale are not credits
    public function test_7c_party_ledger_excludes_payments_on_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $sale = Sale::create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'customer_id' => $customer->id, 'subtotal' => 900, 'discount_amount' => 0, 'total' => 900]);
        $sale->payments()->create(['shop_id' => $shop->id, 'user_id' => $owner->id, 'amount' => 400, 'payment_date' => now()->toDateString()]);
        CancelSale::handle($sale, $owner->id, null);

        Livewire::test('party-ledger.index')
            ->set('customerId', $customer->id)
            ->assertViewHas('statement', fn ($statement) => $statement['sales']['credits'] === 0.0 && $statement['sales']['closing'] === 0.0);
    }

    // 8. Customer::salesOutstandingBalance()
    public function test_8_customer_sales_outstanding_balance_excludes_cancelled(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->cancelledSale($shop->id, $owner->id, $customer->id);

        $this->assertSame(0.0, $customer->fresh()->salesOutstandingBalance());
    }

    // 9. Customer::hasFinancialHistory() — special case, INCLUDES cancelled
    public function test_9_customer_has_financial_history_still_counts_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->cancelledSale($shop->id, $owner->id, $customer->id);

        $this->assertTrue($customer->fresh()->hasFinancialHistory());
    }

    // 10. Udhaar "Other Amounts Owed" list
    public function test_10_udhaar_other_amounts_owed_excludes_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->cancelledSale($shop->id, $owner->id, $customer->id);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('dues', fn ($dues) => $dues->isEmpty());
    }

    // 10b. Udhaar combined headline balance
    public function test_10b_udhaar_combined_balance_excludes_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $this->cancelledSale($shop->id, $owner->id, $customer->id);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->assertViewHas('balance', 0.0)
            ->assertViewHas('status', 'settled');
    }

    // 11. Udhaar settlement cannot be recorded against a cancelled sale
    public function test_11_udhaar_cannot_settle_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Bilal']);
        $sale = $this->cancelledSale($shop->id, $owner->id, $customer->id);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        Livewire::test('udhaar.show', ['customer' => $customer])
            ->call('openSettleDue', 'sale', $sale->id)
            ->call('submitDueSettlement');
    }

    // 12. Invoice download for a cancelled sale is NOT blocked (not an exclusion test — confirms intended inclusion)
    public function test_12_invoice_download_for_a_cancelled_sale_still_works_with_a_watermark(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = $this->cancelledSale($shop->id, $owner->id);

        $html = view('pdf.invoice', ['sale' => $sale->load(['items', 'shop', 'user', 'payments'])])->render();

        $this->assertStringContainsString('CANCELLED', $html);
    }

    // 13. Sale show page is still reachable for a cancelled sale (not an exclusion test)
    public function test_13_sale_show_page_is_still_reachable_for_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = $this->cancelledSale($shop->id, $owner->id);

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Cancelled');
    }

    /**
     * abort_if() inside a Livewire action method is absorbed by Livewire
     * rather than propagating as a raised exception through
     * Livewire::test()->call() (documented precedent: ProductImportAccessTest)
     * — so the guarantee to check is the effect: the modal never opens and
     * no payment ever gets created, not a thrown exception.
     */
    public function test_6_record_payment_is_blocked_on_a_cancelled_sale(): void
    {
        [$shop, $owner] = $this->shopOwner();
        $sale = $this->cancelledSale($shop->id, $owner->id);

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->assertNotDispatched('open-modal');

        Livewire::test('sales.history')
            ->set('payingId', $sale->id)
            ->set('paymentAmount', '100')
            ->call('submitPayment');

        $this->assertSame(0, $sale->payments()->count());
    }
}
