<?php

namespace Tests\Feature;

use App\Actions\CancelGrn;
use App\Actions\CancelSale;
use App\Actions\CancelStockIn;
use App\Actions\CancelStockWriteOff;
use App\Actions\CancelUdhaarTransaction;
use App\Actions\CreateStockIn;
use App\Actions\CreateStockWriteOff;
use App\Actions\CreateUdhaarTransaction;
use App\Actions\ReceiveGoods;
use App\Actions\RecordSalePayment;
use App\Actions\RecordStockInPayment;
use App\Actions\SavePurchaseOrder;
use App\Actions\StartLedger;
use App\Actions\UpdateSale;
use App\Actions\UpdateStockIn;
use App\Actions\VendorInvoices;
use App\Enums\WriteOffReason;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\Customer;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\Vendor;
use App\Models\VendorInvoice;
use App\ReportQueries\LedgerHealthReport;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerGoodsTradeTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Shop $theShop;
    protected Vendor $vendor;
    protected Customer $customer;
    protected Product $cable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = StartLedger::handle($this->shop(['ledger_enabled' => true, 'purchase_orders_enabled' => true]));
        $this->vendor = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Acme']);
        $this->customer = Customer::create(['shop_id' => $this->theShop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
        $this->cable = $this->piece($this->theShop, ['cost_price' => null]);
    }

    protected function stock(float $cost = 100, mixed $quantity = 10, ?Product $product = null, string $status = 'unpaid', ?float $paid = null): StockIn
    {
        return CreateStockIn::handle([['product_id' => ($product ?? $this->cable)->id, 'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => 150.0]], $this->vendor->id, $status, $paid, now()->toDateString(), null, auth()->id())->first();
    }

    protected function drain(): void
    {
        app(LedgerPoster::class)->drain();
    }

    /** @return array<string, float> code => net rupees, debit positive */
    protected function balances(): array
    {
        $this->drain();

        return LedgerLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->groupBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, SUM(amount_paise) as paise')
            ->toBase()
            ->pluck('paise', 'code')
            ->map(fn ($paise) => (float) $paise / 100)
            ->filter()
            ->sortKeys()
            ->all();
    }

    protected function assertBalances(array $expected): void
    {
        ksort($expected, SORT_STRING);

        $this->assertEquals($expected, $this->balances());
        $this->assertSame(0, (int) LedgerLine::sum('amount_paise'), 'Debits must equal credits.');
    }

    protected function assertHealthy(): void
    {
        $this->drain();

        foreach ((new LedgerHealthReport($this->theShop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'], abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }

    // ── Sales ──────────────────────────────────────────────────────────

    public function test_a_stock_in_debits_inventory_and_credits_the_vendor(): void
    {
        $this->stock();

        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -1000]);
        $this->assertSame($this->vendor->id, (int) LedgerLine::where('party_type', 'vendor')->value('party_id'));
        $this->assertHealthy();
    }

    public function test_a_cash_sale_posts_revenue_cash_and_cost(): void
    {
        $this->stock();
        $this->sell($this->cable, 2, paid: 300);
        \App\Models\SalePayment::query()->update(['payment_method' => 'cash']);
        Sale::sole()->touch();

        $this->assertBalances([Chart::INVENTORY => 800, Chart::PAYABLE => -1000, Chart::SALES => -300, Chart::CASH => 300, Chart::COGS => 200]);
        $this->assertHealthy();
    }

    public function test_money_with_no_method_goes_to_not_classified(): void
    {
        $this->stock();
        $this->sell($this->cable, 1, paid: 300);

        $this->assertArrayHasKey(Chart::NOT_CLASSIFIED, $this->balances());
        $this->assertArrayNotHasKey(Chart::CASH, $this->balances());
    }

    public function test_a_payment_into_a_shop_account_gets_its_own_sub_account(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);
        $this->stock();
        $sale = $this->sell($this->cable, 1);

        $sale->payments()->update(['amount' => 300, 'payment_method' => 'account', 'shop_account_id' => $account->id]);
        $sale->touch();

        $this->assertSame(300.0, $this->balances()[Chart::bankCode($account->id)]);
        $this->assertSame('Meezan', \App\Models\LedgerAccount::where('code', Chart::bankCode($account->id))->value('name'));
    }

    public function test_item_and_invoice_discounts_post_to_discounts_and_net_sales_is_right(): void
    {
        $this->stock();

        \App\Actions\CreateSale::handle(lines: [['product_id' => $this->cable->id, 'quantity' => 2, 'discount' => 50.0, 'custom_price' => null]], invoiceDiscount: 25.0, customerId: null, userId: auth()->id(), saleDate: now()->toDateString(), amountPaid: 225);

        $balances = $this->balances();
        $this->assertSame(75.0, $balances[Chart::DISCOUNTS]);
        $this->assertSame(-300.0, $balances[Chart::SALES]);
        $this->assertSame(225.0, $balances[Chart::NOT_CLASSIFIED]);
        $this->assertHealthy();
    }

    public function test_cost_follows_the_batch_across_two_batches(): void
    {
        $this->stock(100, 2);
        $this->stock(120, 5);

        $this->sell($this->cable, 3);

        $this->assertSame(320.0, $this->balances()[Chart::COGS]);
        $this->assertHealthy();
    }

    public function test_a_line_with_no_cost_posts_no_cogs_and_is_surfaced(): void
    {
        $product = $this->piece($this->theShop, ['name' => 'NoCost', 'cost_price' => null, 'stock_quantity' => 5]);
        $this->sell($product, 1);

        $this->assertArrayNotHasKey(Chart::COGS, $this->balances());
        $this->assertSame(1, (new LedgerHealthReport($this->theShop))->counts()['lines_with_no_cost']);
    }

    public function test_a_measured_sale_posts_exact_cost(): void
    {
        $rice = $this->product($this->theShop, ['cost_price' => null]);
        $this->stock(280, 10, $rice);

        $this->sell($rice, 1.5);

        $this->assertSame(420.0, $this->balances()[Chart::COGS]);
        $this->assertHealthy();
    }

    public function test_a_later_payment_posts_on_its_own_date(): void
    {
        $this->stock();
        $sale = $this->sell($this->cable, 1);
        $this->drain();

        $this->travel(3)->days();
        RecordSalePayment::handle($sale, 100, auth()->id());
        $this->drain();

        $this->assertSame(now()->toDateString(), LedgerEntry::where('source_type', 'sale')->latest('id')->first()->entry_date->toDateString());
        $this->assertSame(50.0, $this->balances()[Chart::RECEIVABLE]);
        $this->assertHealthy();
    }

    public function test_editing_a_sale_posts_only_the_difference_even_though_its_items_are_recreated(): void
    {
        $this->stock();
        $sale = $this->sell($this->cable, 2);
        $this->drain();
        $before = LedgerEntry::count();

        UpdateSale::handle($sale, [['product_id' => $this->cable->id, 'product_name' => 'Cable', 'product_type' => 'grocery', 'quantity' => 3, 'discount' => 0.0, 'custom_price' => null]], 0.0, $this->customer->id, auth()->id(), now()->toDateString());

        $balances = $this->balances();
        $this->assertSame(300.0, $balances[Chart::COGS]);
        $this->assertSame(700.0, $balances[Chart::INVENTORY]);
        $this->assertSame($before + 1, LedgerEntry::count());
        $this->assertHealthy();
    }

    public function test_cancelling_a_paid_sale_reverses_it_and_says_the_cash_is_assumed_returned(): void
    {
        $this->stock();
        $sale = $this->sell($this->cable, 2, paid: 300);
        $this->drain();

        CancelSale::handle($sale, auth()->id());

        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -1000]);
        $this->assertStringContainsString('cash assumed returned', LedgerEntry::where('source_type', 'sale')->latest('id')->first()->description);
        $this->assertHealthy();
    }

    public function test_a_cancelled_sales_stock_resells_and_still_balances(): void
    {
        $this->stock();
        $sale = $this->sell($this->cable, 6);
        CancelSale::handle($sale, auth()->id());
        $this->sell($this->cable, 6);

        $this->assertSame(600.0, $this->balances()[Chart::COGS]);
        $this->assertHealthy();
    }

    // ── Udhaar: the two sources of "given" ─────────────────────────────

    public function test_udhaar_given_directly_is_cash_lent_not_revenue(): void
    {
        CreateUdhaarTransaction::handle($this->customer, 'given', '500', now()->toDateString());

        $this->assertBalances([Chart::RECEIVABLE => 500, Chart::CASH => -500]);
        $this->assertArrayNotHasKey(Chart::SALES, $this->balances());
    }

    public function test_a_credit_sales_shortfall_is_goods_on_credit_not_a_cash_loan(): void
    {
        $this->stock();

        $this->sell($this->cable, 2, ['customer_id' => null], 200);

        $balances = $this->balances();
        $this->assertSame(-300.0, $balances[Chart::SALES], 'The whole sale is revenue.');
        $this->assertSame(100.0, $balances[Chart::RECEIVABLE], 'The shortfall is owed.');
        $this->assertArrayNotHasKey(Chart::CASH, $balances, 'No cash left the till.');
        $this->assertSame(0, \App\Models\UdhaarTransaction::count());
    }

    public function test_a_repayment_reduces_what_is_owed_into_not_classified(): void
    {
        CreateUdhaarTransaction::handle($this->customer, 'given', '500', now()->toDateString());
        CreateUdhaarTransaction::handle($this->customer, 'repayment', '200', now()->toDateString());

        $this->assertBalances([Chart::RECEIVABLE => 300, Chart::CASH => -500, Chart::NOT_CLASSIFIED => 200]);
        $this->assertHealthy();
    }

    public function test_reversing_and_cancelling_udhaar_undo_it(): void
    {
        $given = CreateUdhaarTransaction::handle($this->customer, 'given', '500', now()->toDateString());
        $repaid = CreateUdhaarTransaction::handle($this->customer, 'repayment', '100', now()->toDateString());
        \App\Models\UdhaarTransaction::create(['customer_id' => $this->customer->id, 'user_id' => auth()->id(), 'type' => 'repayment_reversal', 'amount' => 100, 'transaction_date' => now()->toDateString(), 'reverses_transaction_id' => $repaid->id]);

        $this->assertBalances([Chart::RECEIVABLE => 500, Chart::CASH => -500]);

        CancelUdhaarTransaction::handle($given, auth()->id());
        $this->assertBalances([]);
        $this->assertHealthy();
    }

    // ── Party ledger ───────────────────────────────────────────────────

    public function test_party_ledger_entries_post_with_the_other_side_in_equity(): void
    {
        PartyLedgerEntry::create(['shop_id' => $this->theShop->id, 'customer_id' => $this->customer->id, 'kind' => 'opening', 'direction' => 'debit', 'amount' => 700, 'entry_date' => now()->toDateString(), 'note' => 'Opening']);
        PartyLedgerEntry::create(['shop_id' => $this->theShop->id, 'vendor_id' => $this->vendor->id, 'kind' => 'adjustment', 'direction' => 'credit', 'amount' => 90, 'entry_date' => now()->toDateString(), 'note' => 'Adjusted']);

        $this->assertBalances([Chart::RECEIVABLE => 700, Chart::OPENING => -700, Chart::PAYABLE => -90, Chart::PRIOR_PERIOD => 90]);
        $this->assertHealthy();
    }

    // ── Vendor payments ────────────────────────────────────────────────

    public function test_paying_a_vendor_reduces_payable_on_stock_in_history_or_at_purchase(): void
    {
        $batch = $this->stock();
        $this->drain();

        // "Paid From" starts on Cash now (Cash & Balances, 2026-11-10).
        Livewire::test('stock-ins.history')->call('openAddPayment', $batch->id)->set('paymentAmount', '400')->call('submitPayment');
        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -600, Chart::CASH => -400]);

        Livewire::test('stock-ins.history')->call('markAsPaid', $batch->id);
        $this->assertBalances([Chart::INVENTORY => 1000, Chart::CASH => -400, Chart::NOT_CLASSIFIED => -600]);
        $this->assertHealthy();

        $this->stock(50, 2, status: 'paid');
        $this->assertBalances([Chart::INVENTORY => 1100, Chart::CASH => -400, Chart::NOT_CLASSIFIED => -700]);
        $this->assertHealthy();
    }

    public function test_an_overpayment_makes_the_vendor_a_debit_and_health_explains_it(): void
    {
        $batch = $this->stock();
        RecordStockInPayment::add($batch, 1200, auth()->id());

        $this->assertSame(200.0, $this->balances()[Chart::PAYABLE]);
        $this->assertSame(0.0, $this->vendor->fresh()->totalOwed());
        $this->assertHealthy();
    }

    public function test_cancelling_a_stock_in_with_money_paid_parks_that_money(): void
    {
        $batch = $this->stock(100, 10, status: 'partial', paid: 300);
        CancelStockIn::handle($batch, auth()->id());

        $this->assertBalances([Chart::CANCELLED_UNCONFIRMED => 300, Chart::NOT_CLASSIFIED => -300]);
        $this->assertHealthy();
    }

    public function test_editing_a_batch_cost_posts_the_difference(): void
    {
        $batch = $this->stock();
        $this->drain();

        UpdateStockIn::handle($batch, ['quantity' => 10, 'vendor_id' => $this->vendor->id, 'stock_date' => now()->toDateString(), 'note' => null, 'cost_price' => 120.0, 'selling_price' => 150.0], auth()->id());

        $this->assertBalances([Chart::INVENTORY => 1200, Chart::PAYABLE => -1200]);
        $this->assertHealthy();
    }

    // ── Goods receipts and vendor invoices ─────────────────────────────

    protected function received(float $cost = 100.0, mixed $quantity = 10): int
    {
        $order = SavePurchaseOrder::handle(null, ['vendor_id' => $this->vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $this->cable->id, 'quantity' => $quantity, 'expected_cost' => $cost]], auth()->id());

        return ReceiveGoods::handle($order->fresh(), [['po_line_id' => $order->lines->first()->id, 'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => 150.0]], now()->toDateString(), null, null, auth()->id())->lines->first()->id;
    }

    protected function bill(int $line, mixed $quantity, float $amount, array $charges = []): VendorInvoice
    {
        return VendorInvoices::record(['vendor_id' => $this->vendor->id, 'invoice_number' => 'B-'.uniqid(), 'invoice_date' => now()->toDateString()], [['grn_line_id' => $line, 'billed_quantity' => $quantity, 'billed_amount' => $amount]], $charges, auth()->id());
    }

    public function test_a_purchase_order_posts_nothing_and_a_goods_receipt_posts_like_stock_in(): void
    {
        SavePurchaseOrder::handle(null, ['vendor_id' => $this->vendor->id, 'branch_id' => null, 'order_date' => now()->toDateString(), 'expected_date' => null, 'note' => null], [['product_id' => $this->cable->id, 'quantity' => 5, 'expected_cost' => 100.0]], auth()->id());
        $this->assertBalances([]);

        $this->received();
        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -1000]);
    }

    public function test_cancelling_a_goods_receipt_reverses_it(): void
    {
        $line = $this->received();
        $this->drain();

        CancelGrn::handle(StockIn::where('grn_line_id', $line)->sole()->grnLine->grn, auth()->id());

        $this->assertBalances([]);
        $this->assertHealthy();
    }

    public function test_an_invoice_correction_splits_between_inventory_and_sold_units_on_the_approval_date(): void
    {
        $line = $this->received();
        $this->sell($this->cable, 6);
        $this->drain();

        $this->travel(4)->days();
        VendorInvoices::approve($this->bill($line, 10, 1200), 'Price rose', auth()->id());

        // 200 more owed: 4 of 10 unsold, so 80 to Inventory, 120 to Adjustments.
        $balances = $this->balances();
        $this->assertSame(-1200.0, $balances[Chart::PAYABLE]);
        $this->assertSame(480.0, $balances[Chart::INVENTORY]);
        $this->assertSame(120.0, $balances[Chart::ADJUSTMENTS]);
        $this->assertSame(600.0, $balances[Chart::COGS]);

        $correction = LedgerLine::where('origin_account_code', Chart::ADJUSTMENTS)->sole();
        $this->assertSame(now()->toDateString(), $correction->entry->entry_date->toDateString());
        $this->assertHealthy();
    }

    public function test_a_rounded_unit_cost_keeps_the_exact_billed_total_in_payable(): void
    {
        $line = $this->received(30.0, 3);

        VendorInvoices::approve($this->bill($line, 3, 100), 'OK', auth()->id());

        $this->assertBalances([Chart::INVENTORY => 100, Chart::PAYABLE => -100]);
        $this->assertHealthy();
    }

    public function test_cancelling_an_approved_invoice_reverses_the_correction(): void
    {
        $line = $this->received();
        $invoice = VendorInvoices::approve($this->bill($line, 10, 1200), 'OK', auth()->id());
        $this->drain();

        VendorInvoices::cancel($invoice, auth()->id());

        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -1000]);
        $this->assertHealthy();
    }

    public function test_a_disputed_invoice_posts_nothing(): void
    {
        $line = $this->received();
        $this->drain();
        $before = LedgerEntry::count();

        VendorInvoices::dispute($this->bill($line, 10, 1500), 'Wrong', auth()->id());
        $this->drain();

        $this->assertSame($before, LedgerEntry::count());
    }

    public function test_paying_an_invoice_reduces_payable(): void
    {
        $line = $this->received();
        $invoice = $this->bill($line, 10, 1000);

        VendorInvoices::pay($invoice, 1000, auth()->id());

        $this->assertBalances([Chart::INVENTORY => 1000, Chart::NOT_CLASSIFIED => -1000]);
        $this->assertHealthy();
    }

    // ── Write-offs ─────────────────────────────────────────────────────

    public function test_a_write_off_moves_cost_from_inventory_and_cancelling_reverses(): void
    {
        $this->stock();

        $writeOff = CreateStockWriteOff::handle($this->cable, 3, WriteOffReason::Damaged, null, auth()->id(), now()->toDateString());

        $this->assertBalances([Chart::INVENTORY => 700, Chart::PAYABLE => -1000, Chart::WRITE_OFFS => 300]);
        $this->assertHealthy();

        CancelStockWriteOff::handle($writeOff, auth()->id());
        $this->assertBalances([Chart::INVENTORY => 1000, Chart::PAYABLE => -1000]);
        $this->assertHealthy();
    }

    // ── Stock no batch covers ──────────────────────────────────────────

    public function test_stock_typed_on_a_product_posts_on_the_day_and_selling_it_balances(): void
    {
        $product = Product::create(['shop_id' => $this->theShop->id, 'type' => 'grocery', 'name' => 'Loose', 'price' => 100, 'cost_price' => 40, 'stock_quantity' => 10, 'details' => ['category' => null]]);

        $this->assertBalances([Chart::INVENTORY => 400, Chart::ADJUSTMENTS => -400]);

        $this->sell($product, 4);

        $balances = $this->balances();
        $this->assertSame(240.0, $balances[Chart::INVENTORY]);
        $this->assertSame(160.0, $balances[Chart::COGS]);
        $this->assertHealthy();
    }

    public function test_correcting_stock_on_the_product_form_posts_only_the_change_dated_today(): void
    {
        $product = Product::create(['shop_id' => $this->theShop->id, 'type' => 'grocery', 'name' => 'Loose', 'price' => 100, 'cost_price' => 40, 'stock_quantity' => 10, 'details' => ['category' => null]]);
        $this->drain();

        $this->travel(5)->days();
        $product->update(['stock_quantity' => 7]);

        $this->assertBalances([Chart::INVENTORY => 280, Chart::ADJUSTMENTS => -280]);
        $this->assertSame(now()->toDateString(), LedgerEntry::where('source_type', 'product_stock')->latest('id')->first()->entry_date->toDateString());
        $this->assertHealthy();
    }

    public function test_receiving_and_selling_batches_never_posts_a_stock_correction(): void
    {
        $this->stock();
        $sale = $this->sell($this->cable, 3);
        $this->cable->fresh()->update(['name' => 'Renamed Cable']);
        CancelSale::handle($sale, auth()->id());
        $this->cable->fresh()->update(['name' => 'Cable Again']);

        $this->drain();

        $this->assertSame(0, LedgerEntry::where('source_type', 'product_stock')->count());
    }

    public function test_stock_below_its_batches_values_negative_and_still_reconciles(): void
    {
        $this->stock();
        $this->cable->fresh()->forceFill(['stock_quantity' => 8, 'cost_price' => 100])->save();

        $this->assertSame(800.0, $this->balances()[Chart::INVENTORY]);
        $this->assertHealthy();
    }

    // ── Whole picture ──────────────────────────────────────────────────

    public function test_a_busy_day_balances_and_reconciles_with_every_screen(): void
    {
        $batch = $this->stock(100, 20);
        $rice = $this->product($this->theShop, ['cost_price' => null]);
        $this->stock(280, 10, $rice, 'partial', 500);
        $line = $this->received(100.0, 5);

        $a = $this->sell($this->cable, 3, paid: 450);
        $b = \App\Actions\CreateSale::handle(lines: [['product_id' => $rice->id, 'quantity' => 2.5, 'discount' => 20.0, 'custom_price' => null]], invoiceDiscount: 10.0, customerId: $this->customer->id, userId: auth()->id(), saleDate: now()->toDateString(), amountPaid: 100);
        RecordSalePayment::handle($b, 50, auth()->id());
        CreateUdhaarTransaction::handle($this->customer, 'given', '1000', now()->toDateString());
        CreateUdhaarTransaction::handle($this->customer, 'repayment', '250', now()->toDateString());
        RecordStockInPayment::add($batch, 700, auth()->id());
        CreateStockWriteOff::handle($this->cable, 1, WriteOffReason::Damaged, null, auth()->id(), now()->toDateString());
        VendorInvoices::approve($this->bill($line, 5, 600), 'OK', auth()->id());
        CancelSale::handle($a, auth()->id());

        $this->assertHealthy();
        $this->assertSame(0, (int) LedgerLine::sum('amount_paise'));

        // Agrees with the statement the shop already trusts.
        $owedByLedger = LedgerLine::where('party_type', 'customer')->where('party_id', $this->customer->id)->sum('amount_paise') / 100;
        $this->assertEqualsWithDelta(CustomerDuesStatement::for($this->customer->fresh())->total(), $owedByLedger, 0.001);
        $this->assertEqualsWithDelta($this->vendor->fresh()->totalOwed(), -LedgerLine::where('party_type', 'vendor')->sum('amount_paise') / 100, 0.001);
    }

    public function test_the_same_day_with_the_ledger_on_or_off_leaves_every_figure_identical(): void
    {
        $run = function (Shop $shop) {
            $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'V']);
            $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'C', 'phone' => '0300'.random_int(1000000, 9999999)]);
            $product = $this->piece($shop, ['cost_price' => null]);
            CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 5, 'cost_price' => 90.0, 'selling_price' => 140.0]], $vendor->id, 'unpaid', null, now()->toDateString(), null, auth()->id());
            CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 5, 'cost_price' => 110.0, 'selling_price' => 140.0]], $vendor->id, 'partial', 100.0, now()->toDateString(), null, auth()->id());
            $sale = \App\Actions\CreateSale::handle(lines: [['product_id' => $product->id, 'quantity' => 7, 'discount' => 0.0, 'custom_price' => null]], invoiceDiscount: 0.0, customerId: $customer->id, userId: auth()->id(), saleDate: now()->toDateString(), amountPaid: 200);
            CreateUdhaarTransaction::handle($customer, 'given', '500', now()->toDateString());
            app(LedgerPoster::class)->drain();

            return [
                'owed' => $vendor->fresh()->totalOwed(),
                'dues' => CustomerDuesStatement::for($customer->fresh())->total(),
                'stock' => $product->fresh()->stock_quantity,
                'batches' => StockIn::where('product_id', $product->id)->orderBy('id')->pluck('quantity_remaining')->all(),
                'costs' => $sale->items()->orderBy('id')->pluck('cost_price')->all(),
                'total' => (float) $sale->fresh()->total,
            ];
        };

        $on = $run($this->theShop);
        $off = $run($this->shop(['name' => 'Off']));

        $this->assertEquals($off, $on);
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_account_ledger_drills_down_and_is_owner_only(): void
    {
        $this->stock();
        $this->drain();

        $this->get(route('ledger.trial-balance'))->assertSee(route('ledger.account', Chart::INVENTORY));

        Livewire::test('ledger.account', ['code' => Chart::INVENTORY])
            ->assertSee('Stock in: Cable')
            ->assertSee('Rs 1,000.00 Dr')
            ->set('from', now()->addDay()->toDateString())
            ->assertSee('No entries')
            ->assertSee('Rs 1,000.00 Dr');

        $this->get(route('ledger.health'))->assertOk();

        $role = \App\Models\Role::create(['shop_id' => $this->theShop->id, 'name' => 'M', 'permissions' => ['stock-ins']]);
        $this->actingAs(\App\Models\User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $this->get(route('ledger.account', Chart::INVENTORY))->assertForbidden();
        $this->get(route('ledger.health'))->assertForbidden();
    }

    public function test_health_screen_shows_a_real_difference(): void
    {
        $this->stock();
        $this->drain();
        \Illuminate\Support\Facades\DB::table('stock_ins')->update(['total_cost' => 900]);

        $report = new LedgerHealthReport($this->theShop->fresh());

        $this->assertFalse($report->isHealthy());
        Livewire::test('ledger.health')->assertSee('Vendors are owed')->assertSee('Rs 100.00');
    }
}
