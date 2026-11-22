<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\SaleReturns;
use App\Actions\StartLedger;
use App\Actions\UpdateSale;
use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerPoster;
use App\Models\Customer;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\StockWriteOff;
use App\Models\User;
use App\ReportQueries\LedgerHealthReport;
use App\ReportQueries\ProfitReport;
use App\ReportQueries\SalesReport;
use App\Services\ShopReportService;
use App\Support\BatchExpiry;
use App\Support\MoneyFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Returns core (R1): partial returns back to the exact batch, values after
 * line and bill discounts, what a return does to what's owed, refunds through
 * Cash & Balances and the ledger, the shop's return policy, and the guards on
 * editing or cancelling a sale that has a return.
 */
class SaleReturnTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Libas', 'business_category' => 'clothing']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha', 'phone' => '03001234567']);
        $this->actingAs($this->owner);
    }

    protected function product(string $name = 'Lawn Kurta', float $price = 2000): Product
    {
        return Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => $name, 'price' => $price, 'stock_quantity' => 0, 'details' => ['category' => null]]);
    }

    protected function batch(Product $product, int $quantity, float $cost, float $selling, string $date = '2026-11-01'): StockIn
    {
        return CreateStockIn::handle([['product_id' => $product->id, 'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => $selling]], null, 'paid', null, $date, null, $this->owner->id)->sole();
    }

    /** @param array<int, array{0: Product, 1: int|float, 2?: float}> $lines product, quantity, line discount */
    protected function sell(array $lines, float $paid, float $invoiceDiscount = 0.0, ?int $customerId = null, ?string $date = null, array $installments = []): Sale
    {
        return CreateSale::handle(
            lines: array_map(fn (array $line) => ['product_id' => $line[0]->id, 'quantity' => $line[1], 'discount' => $line[2] ?? 0.0, 'custom_price' => null], $lines),
            invoiceDiscount: $invoiceDiscount,
            customerId: $customerId ?? $this->customer->id,
            userId: $this->owner->id,
            saleDate: $date ?? now()->toDateString(),
            amountPaid: $paid,
            paymentMethod: 'cash',
            installments: $installments,
        );
    }

    protected function key(Product $product): string
    {
        return 'p'.$product->id;
    }

    protected function return(Sale $sale, array $quantities, ?string $method = 'cash', ?int $account = null, array $damaged = [], ?string $date = null, ?string $late = null, ?bool $byOwner = true): SaleReturn
    {
        $lines = [];

        foreach ($quantities as $productId => $quantity) {
            $lines['p'.$productId] = ['quantity' => (string) $quantity, 'disposition' => in_array($productId, $damaged, true) ? 'damaged' : 'restock'];
        }

        return SaleReturns::create($sale->fresh(), $lines, $method, $account, null, $this->owner->id, $date, $late, byOwner: $byOwner);
    }

    protected function refunds(): float
    {
        return (float) (new MoneyFlow($this->shop->id))->movements()->where('source', 'refund')->sum('amount');
    }

    // ── Stock goes back to the exact batch ──────────────────────────────

    public function test_a_partial_return_goes_back_to_the_batches_it_was_sold_from_newest_first(): void
    {
        $kurta = $this->product();
        $old = $this->batch($kurta, 5, 1000, 2000, '2026-10-01');
        $new = $this->batch($kurta, 5, 1200, 2000, '2026-10-15');

        $sale = $this->sell([[$kurta, 8]], 16000);
        $this->assertSame([0, 2], [$old->fresh()->quantity_remaining, $new->fresh()->quantity_remaining]);

        $return = $this->return($sale, [$kurta->id => 4]);

        // The 3 from the newer batch go back first, then 1 from the older one.
        $this->assertSame([1, 5], [$old->fresh()->quantity_remaining, $new->fresh()->quantity_remaining]);
        $this->assertSame(6, $kurta->fresh()->stock_quantity);
        $this->assertSame(8000.0, (float) $return->value_total);
        $this->assertEqualsCanonicalizing([[$new->id, 3, '1200.00'], [$old->id, 1, '1000.00']], $return->items->map(fn ($item) => [$item->stock_in_id, $item->quantity, $item->cost_price])->all());

        // The rest comes back from the older batch.
        $this->return($sale, [$kurta->id => 4]);
        $this->assertSame([5, 5], [$old->fresh()->quantity_remaining, $new->fresh()->quantity_remaining]);
        $this->assertSame(10, $kurta->fresh()->stock_quantity);
    }

    public function test_a_returned_unit_sells_again_at_the_cost_it_came_back_with(): void
    {
        $kurta = $this->product();
        $first = $this->batch($kurta, 1, 1000, 2000, '2026-10-01');
        $this->batch($kurta, 1, 1500, 2500, '2026-10-15');

        $sale = $this->sell([[$kurta, 1]], 2000);
        $this->return($sale, [$kurta->id => 1]);

        $again = $this->sell([[$kurta, 1]], 2000);
        $item = $again->items()->sole();

        $this->assertSame($first->id, $item->stock_in_id, 'the older batch still sells first');
        $this->assertSame('1000.00', $item->cost_price);
    }

    public function test_fractional_quantities_return_exactly(): void
    {
        $rice = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Rice', 'price' => 300, 'stock_quantity' => 0, 'unit' => 'kg', 'details' => ['category' => null]]);
        $batch = $this->batch($rice, 10, 250, 300);

        $sale = $this->sell([[$rice, 2.5]], 750);
        $return = $this->return($sale, [$rice->id => 0.75]);

        $this->assertEquals(8.25, $batch->fresh()->quantity_remaining);
        $this->assertSame(225.0, (float) $return->value_total);
    }

    // ── Values after discounts ─────────────────────────────────────────

    public function test_values_take_off_line_and_bill_discounts_and_a_whole_sale_returns_exactly_its_total(): void
    {
        $shirt = $this->product('Shirt', 1000);
        $jeans = $this->product('Jeans', 3000);
        $this->batch($shirt, 10, 500, 1000);
        $this->batch($jeans, 10, 1500, 3000);

        // Lines: 3 x 1000 - 100 = 2900, 1 x 3000 = 3000; bill discount 100.01 split by line total.
        $sale = $this->sell([[$shirt, 3, 100.0], [$jeans, 1]], 5799.99, invoiceDiscount: 100.01);
        $this->assertSame(5799.99, (float) $sale->total);

        $one = $this->return($sale, [$shirt->id => 1]);
        $two = $this->return($sale, [$shirt->id => 1]);
        $rest = $this->return($sale, [$shirt->id => 1, $jeans->id => 1]);

        $this->assertSame(5799.99, round((float) $one->value_total + (float) $two->value_total + (float) $rest->value_total, 2));
        $this->assertSame($one->value_total, $two->value_total);
        $this->assertSame(0.0, $sale->fresh()->amountDue());

        $this->expectException(ValidationException::class);
        $this->return($sale, [$jeans->id => 1]);
    }

    // ── What's owed, and refunds ───────────────────────────────────────

    public function test_an_unpaid_sale_just_owes_less_and_nothing_is_handed_back(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 0);

        $return = $this->return($sale, [$kurta->id => 1]);

        $this->assertSame(2000.0, (float) $return->due_reduction);
        $this->assertSame(0.0, (float) $return->refund_cash);
        $this->assertSame(2000.0, $sale->fresh()->amountDue());
        $this->assertSame((float) $sale->total, (float) $sale->fresh()->total, 'the sale itself is never changed');
        $this->assertSame(2000.0, $this->customer->salesOutstandingBalance());
        $this->assertSame(0.0, $this->refunds());
    }

    public function test_a_part_paid_sale_cancels_what_is_owed_first_and_hands_back_the_rest(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 5000);

        $return = $this->return($sale, [$kurta->id => 2]);

        $this->assertSame(1000.0, (float) $return->due_reduction);
        $this->assertSame(3000.0, (float) $return->refund_cash);
        $this->assertSame(0.0, $sale->fresh()->amountDue());
        $this->assertSame(5000.0, $sale->fresh()->amountPaid(), 'payments stay as they were');
        $this->assertSame(-3000.0, $this->refunds());
    }

    public function test_a_refund_leaves_the_cash_or_account_it_was_paid_from_on_the_return_date(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $account = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'JazzCash', 'provider_type' => 'jazzcash']);
        $sale = $this->sell([[$kurta, 2]], 4000, date: now()->subDays(3)->toDateString());

        $this->return($sale, [$kurta->id => 1], 'account', $account->id);

        $row = (new MoneyFlow($this->shop->id))->movements()->firstWhere('source', 'refund');
        $this->assertSame('account:'.$account->id, $row['bucket']);
        $this->assertSame(-2000.0, $row['amount']);
        $this->assertSame(BatchExpiry::today(), $row['date']);
        $this->assertStringStartsWith('Refund RN-', $row['label']);

        // The sale's own payment stays on its own day, untouched.
        $this->assertSame(4000.0, (float) (new MoneyFlow($this->shop->id))->movements()->where('source', 'sale')->sum('amount'));
    }

    public function test_a_return_pays_off_the_earliest_instalments_first(): void
    {
        $phone = $this->product('Tablet', 30000);
        $this->batch($phone, 2, 20000, 30000);
        $sale = $this->sell([[$phone, 2]], 0, installments: [
            ['due_date' => now()->addMonth()->toDateString(), 'amount' => 20000],
            ['due_date' => now()->addMonths(2)->toDateString(), 'amount' => 20000],
            ['due_date' => now()->addMonths(3)->toDateString(), 'amount' => 20000],
        ]);

        $this->return($sale, [$phone->id => 1]);

        $rows = \App\Support\InstallmentSchedule::for($sale->fresh())->rows();
        $this->assertSame(['paid', 'upcoming', 'upcoming'], $rows->pluck('status')->all());
        $this->assertSame([0.0, 10000.0, 20000.0], $rows->pluck('left')->all());
    }

    public function test_a_shop_that_gives_no_money_back_puts_the_rest_on_store_credit_and_refuses_money(): void
    {
        $this->shop->update(['cash_refunds_allowed' => false]);
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 2000);

        // 2000 owed: one comes off it.
        $this->return($sale, [$kurta->id => 1]);

        // Asking for money is refused, and changes nothing.
        try {
            SaleReturns::create($sale->fresh(), [$this->key($kurta) => ['quantity' => '1']], 'cash', null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CASH);
            $this->fail('A refund in money should be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('refund', $e->errors());
        }

        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(4, $kurta->fresh()->stock_quantity, 'the refused return put nothing back');

        // The rest of a return now goes on the customer's store credit.
        $credit = $this->return($sale, [$kurta->id => 1]);
        $this->assertSame(0.0, (float) $credit->refund_cash);
        $this->assertSame(2000.0, (float) $credit->refund_credit);
    }

    // ── Policy and refusals ─────────────────────────────────────────────

    public function test_past_the_return_window_only_the_owner_can_take_goods_back_and_must_say_why(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000, '2026-01-01');
        $sale = $this->sell([[$kurta, 2]], 4000);
        $sale->forceFill(['created_at' => now()->subDays(40)])->save();

        try {
            $this->return($sale, [$kurta->id => 1], byOwner: false);
            $this->fail('Staff should be refused past the window.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('within 30 days', $e->errors()['lines'][0]);
        }

        try {
            $this->return($sale, [$kurta->id => 1]);
            $this->fail('The owner has to give a reason.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('lateReason', $e->errors());
        }

        $return = $this->return($sale, [$kurta->id => 1], late: 'Unworn, customer was abroad');
        $this->assertSame('Unworn, customer was abroad', $return->late_reason);

        // No limit at all.
        $this->shop->update(['return_window_days' => 0]);
        $this->assertNull($this->return($sale, [$kurta->id => 1], byOwner: false)->late_reason);
    }

    public function test_bad_returns_are_refused_and_change_nothing(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 4000);

        $refused = fn (array $quantities, ?string $date = null) => $this->assertThrows(fn () => $this->return($sale, $quantities, date: $date), ValidationException::class);

        $refused([$kurta->id => 3]);
        $refused([$kurta->id => 0]);
        $refused([$kurta->id => 1.5]);
        $refused([999999 => 1]);
        $refused([$kurta->id => 1], now()->addDay()->toDateString());
        $refused([$kurta->id => 1], now()->subYears(2)->toDateString());

        CancelSale::handle($sale->fresh(), $this->owner->id);
        $refused([$kurta->id => 1]);

        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(5, $kurta->fresh()->stock_quantity);
    }

    // ── Guards on the sale ─────────────────────────────────────────────

    public function test_a_sale_with_a_return_cannot_be_edited_or_cancelled_until_the_return_is(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 4000);
        $return = $this->return($sale, [$kurta->id => 1]);

        $this->assertThrows(fn () => CancelSale::handle($sale->fresh(), $this->owner->id), ValidationException::class, 'Cancel the return first');
        $this->assertThrows(fn () => UpdateSale::handle($sale->fresh(), [['product_id' => $kurta->id, 'product_name' => $kurta->name, 'product_type' => 'accessory', 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0.0, $this->customer->id, $this->owner->id, now()->toDateString()), ValidationException::class);

        Livewire::test('sales.show', ['sale' => $sale])->assertDontSee('>Edit<', false)->assertSee('Return Items');
        Livewire::test('sales.edit', ['sale' => $sale])->assertRedirect(route('sales.show', $sale));

        SaleReturns::cancel($return, $this->owner->id);
        CancelSale::handle($sale->fresh(), $this->owner->id);
        $this->assertTrue($sale->fresh()->isCancelled());
    }

    // ── Cancelling a return ─────────────────────────────────────────────

    public function test_cancelling_a_return_takes_the_goods_out_again_and_restores_what_is_owed(): void
    {
        $kurta = $this->product();
        $batch = $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 5000);
        $return = $this->return($sale, [$kurta->id => 2]);

        SaleReturns::cancel($return, $this->owner->id, 'Wrong sale');

        $this->assertTrue($return->fresh()->isCancelled());
        $this->assertSame(2, $batch->fresh()->quantity_remaining);
        $this->assertSame(2, $kurta->fresh()->stock_quantity);
        $this->assertSame(1000.0, $sale->fresh()->amountDue());
        $this->assertSame(0.0, $this->refunds());
        $this->assertSame('Wrong sale', ModuleRevision::where('revisionable_type', SaleReturn::class)->sole()->reason);
        $this->assertThrows(fn () => SaleReturns::cancel($return, $this->owner->id), ValidationException::class);
    }

    public function test_a_return_whose_goods_have_sold_again_cannot_be_cancelled(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 1, 1000, 2000);
        $sale = $this->sell([[$kurta, 1]], 2000);
        $return = $this->return($sale, [$kurta->id => 1]);
        $this->sell([[$kurta, 1]], 2000);

        $this->assertThrows(fn () => SaleReturns::cancel($return, $this->owner->id), ValidationException::class, 'sold again');
        $this->assertFalse($return->fresh()->isCancelled());
    }

    // ── Damaged, old stock, phones ──────────────────────────────────────

    public function test_damaged_goods_are_written_off_from_the_batch_they_came_back_to(): void
    {
        $kurta = $this->product();
        $batch = $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 4000);

        $return = $this->return($sale, [$kurta->id => 1], damaged: [$kurta->id]);

        $writeOff = StockWriteOff::sole();
        $this->assertSame($writeOff->id, $return->items->sole()->stock_write_off_id);
        $this->assertSame($batch->id, $writeOff->items()->sole()->stock_in_id);
        $this->assertSame('1000.00', $writeOff->total_cost);
        $this->assertSame(3, $kurta->fresh()->stock_quantity);
        $this->assertSame(3, $batch->fresh()->quantity_remaining);
        $this->assertSame(2000.0, (float) $return->refund_cash, 'the customer is refunded either way');

        SaleReturns::cancel($return, $this->owner->id);
        $this->assertTrue(StockWriteOff::withoutGlobalScopes()->find($writeOff->id)->isCancelled());
        $this->assertSame(3, $kurta->fresh()->stock_quantity);
    }

    public function test_old_stock_with_no_batch_goes_back_to_the_product_and_damaged_old_stock_stays_sold(): void
    {
        $legacy = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Old Shawl', 'price' => 900, 'cost_price' => 500, 'stock_quantity' => 4, 'details' => ['category' => null]]);
        $sale = $this->sell([[$legacy, 3]], 2700);
        $this->assertNull($sale->items()->first()->stock_in_id);

        $this->return($sale, [$legacy->id => 1]);
        $this->assertSame(2, $legacy->fresh()->stock_quantity);

        $damaged = $this->return($sale, [$legacy->id => 1], damaged: [$legacy->id]);
        $this->assertFalse($damaged->items->sole()->restocked);
        $this->assertSame(2, $legacy->fresh()->stock_quantity);
        $this->assertSame(0, StockWriteOff::count());
    }

    public function test_a_returned_phone_goes_back_in_stock_and_its_warranty_shows_returned(): void
    {
        $this->shop->update(['business_category' => 'mobile_electronics']);
        $phone = Product::create(['shop_id' => $this->shop->id, 'type' => 'mobile', 'name' => 'Samsung A15', 'price' => 45000, 'cost_price' => 39000, 'stock_quantity' => 0,
            'warranty_months' => 12, 'warranty_type' => 'brand', 'details' => ['brand' => 'Samsung', 'model' => 'A15', 'imei' => '351756051523999', 'category' => null]]);
        $this->batch($phone, 1, 39000, 45000);
        $sale = $this->sell([[$phone, 1]], 45000);
        $phone->update(['archived_at' => now()]);

        $return = $this->return($sale, [$phone->id => 1]);

        $this->assertSame(1, $phone->fresh()->stock_quantity);
        $this->assertNull($phone->fresh()->archived_at);
        $this->assertSame('351756051523999', $return->items->sole()->serial_number);

        Livewire::test('sales.warranty')->set('search', '351756051523999')->assertSee('Returned');
    }

    public function test_a_sale_with_a_trade_in_can_be_returned_with_a_warning_and_the_shop_keeps_the_phone(): void
    {
        $this->shop->update(['business_category' => 'mobile_electronics']);
        \App\Models\MainCategory::ensureDefaultsExist($this->shop->id);
        $phone = Product::create(['shop_id' => $this->shop->id, 'type' => 'mobile', 'name' => 'Samsung S23', 'price' => 70000, 'cost_price' => 60000, 'stock_quantity' => 0,
            'details' => ['brand' => 'Samsung', 'model' => 'S23', 'imei' => '490154203237518', 'category' => null]]);
        $this->batch($phone, 1, 60000, 70000);

        $sale = CreateSale::handle(
            lines: [['product_id' => $phone->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0, customerId: $this->customer->id, userId: $this->owner->id, saleDate: now()->toDateString(), amountPaid: 40000, paymentMethod: 'cash',
            tradeIn: ['brand' => 'Apple', 'model' => 'iPhone 11', 'imei' => '356938035643809', 'condition' => 'good', 'price' => 30000, 'selling_price' => 36000, 'seller_cnic' => '3520212345671'],
        );

        Livewire::test('sales.return', ['sale' => $sale])->assertSee('A traded-in phone paid for part of this sale');

        $return = $this->return($sale, [$phone->id => 1]);

        $this->assertSame(70000.0, (float) $return->refund_cash, 'refunded in money, the traded-in phone stays');
        $this->assertSame(1, $phone->fresh()->stock_quantity);
        $this->assertSame(1, \App\Models\Buyback::where('sale_id', $sale->id)->where('status', 'active')->count());
    }

    // ── Ledger ──────────────────────────────────────────────────────────

    public function test_the_ledger_books_a_return_on_its_day_and_stays_healthy_through_a_cancel(): void
    {
        if ($this->shop->fresh()->ledger_started_at === null) {
            StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        }

        // A ledger that started before Sales Returns existed gets it on first use.
        DB::table('ledger_accounts')->where('shop_id', $this->shop->id)->where('code', ChartOfAccounts::SALES_RETURNS)->delete();

        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 5000);
        $return = $this->return($sale, [$kurta->id => 2]);
        app(LedgerPoster::class)->drain();

        $balances = $this->ledgerBalances();
        $this->assertSame(400000, $balances[ChartOfAccounts::SALES_RETURNS] ?? null);
        $this->assertSame(0, $balances[ChartOfAccounts::RECEIVABLE] ?? 0, 'the 1000 owed was cancelled');
        $this->assertSame(500000 - 300000, $balances[ChartOfAccounts::CASH] ?? null);
        $this->assertSame(100000, $balances[ChartOfAccounts::COGS] ?? null, 'cost of the one kurta kept');
        $this->assertHealthy();

        SaleReturns::cancel($return, $this->owner->id);
        app(LedgerPoster::class)->drain();
        $balances = $this->ledgerBalances();
        $this->assertSame(0, $balances[ChartOfAccounts::SALES_RETURNS] ?? 0);
        $this->assertSame(300000, $balances[ChartOfAccounts::COGS] ?? null);
        $this->assertHealthy();
    }

    public function test_the_ledger_stays_healthy_with_old_stock_and_damaged_returns(): void
    {
        if ($this->shop->fresh()->ledger_started_at === null) {
            StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        }

        $legacy = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Old Shawl', 'price' => 900, 'cost_price' => 500, 'stock_quantity' => 4, 'details' => ['category' => null]]);
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);

        $sale = $this->sell([[$legacy, 2], [$kurta, 2]], 0);
        $this->return($sale, [$legacy->id => 1, $kurta->id => 1], damaged: [$kurta->id]);

        $this->assertHealthy();
    }

    // ── Reports ─────────────────────────────────────────────────────────

    public function test_reports_keep_gross_sales_and_add_returns_on_the_day_they_happen(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 6000, date: now()->subDays(2)->toDateString());
        $sale->forceFill(['created_at' => now()->subDays(2)])->save();
        $this->return($sale, [$kurta->id => 1]);

        $today = app(ShopReportService::class)->summary($this->shop, now()->startOfDay(), now()->endOfDay());
        $this->assertSame(0.0, $today['totalRevenue']);
        $this->assertSame(2000.0, $today['totalReturns']);
        $this->assertSame(-2000.0, $today['netSummary']);

        $all = app(ShopReportService::class)->summary($this->shop, now()->subWeek(), now()->endOfDay());
        $this->assertSame(6000.0, $all['totalRevenue']);
        $this->assertSame(4000.0, $all['netSales']);

        $cards = collect((new SalesReport)->summaryCards())->pluck('value', 'label');
        $this->assertSame('Rs 6,000.00', $cards['Total Revenue']);
        $this->assertSame('Rs 2,000.00', $cards['Returns']);
        $this->assertSame('Rs 4,000.00', $cards['Net Sales']);

        $profit = (new ProfitReport)->totals();
        $this->assertSame(4000.0, $profit['revenue']);
        $this->assertSame(2000.0, $profit['cogs']);
        $this->assertSame(2000.0, $profit['grossProfit']);
        $this->assertSame(2000.0, $profit['returns']);
    }

    public function test_the_customer_statement_shows_the_return_and_refund_and_ends_at_what_is_owed(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 5000);
        $this->return($sale, [$kurta->id => 2]);

        Livewire::withQueryParams(['party' => 'customer', 'customer' => $this->customer->id])
            ->test('party-ledger.index')
            ->assertSee('Return RN-')
            ->assertSee('Refund paid');

        $this->assertSame(0.0, $this->customer->salesOutstandingBalance());
    }

    // ── Screens ─────────────────────────────────────────────────────────

    public function test_the_return_screen_previews_the_refund_and_records_the_return(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 5000);

        Livewire::test('sales.return', ['sale' => $sale])
            ->set('quantities.'.$this->key($kurta), '2')
            ->assertSeeHtml('data-return-value>Rs 4,000.00')
            ->assertSeeHtml('data-return-refund>Rs 3,000.00')
            ->set('reason', 'Wrong size')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('sales.show', $sale));

        $return = SaleReturn::sole();
        $this->assertSame('Wrong size', $return->reason);
        $this->assertSame(3000.0, (float) $return->refund_cash);

        Livewire::test('sales.show', ['sale' => $sale])
            ->assertSee($return->number())
            ->assertSee('Rs 3,000.00 handed back from Cash')
            ->call('downloadCreditNote', $return->id)
            ->assertFileDownloaded($return->number().'.pdf');

        Livewire::test('sales.history')->assertSeeHtml('data-has-returns');
    }

    public function test_return_everything_fills_what_is_left_and_the_screen_shows_errors(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 3]], 6000);
        $this->return($sale, [$kurta->id => 1]);

        Livewire::test('sales.return', ['sale' => $sale])
            ->call('returnAll')
            ->assertSet('quantities.'.$this->key($kurta), '2')
            ->set('quantities.'.$this->key($kurta), '5')
            ->call('save')
            ->assertHasErrors('lines.'.$this->key($kurta).'.quantity');

        Livewire::test('sales.return', ['sale' => $sale])->call('save')->assertHasErrors('lines');
    }

    public function test_cancelling_a_return_from_the_sale_page(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 4000);
        $return = $this->return($sale, [$kurta->id => 1]);

        Livewire::test('sales.show', ['sale' => $sale])
            ->call('openCancelReturn', $return->id)
            ->set('returnCancelReason', 'Mistake')
            ->call('cancelReturn')
            ->assertHasNoErrors();

        $this->assertTrue($return->fresh()->isCancelled());
    }

    public function test_staff_need_the_returns_permission(): void
    {
        $kurta = $this->product();
        $this->batch($kurta, 5, 1000, 2000);
        $sale = $this->sell([[$kurta, 2]], 4000);

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $this->get(route('sales.return', $sale))->assertForbidden();
        Livewire::test('sales.show', ['sale' => $sale])->assertDontSee('Return Items');

        $role->update(['permissions' => ['sales', 'sales-returns']]);
        $this->actingAs($staff->fresh());
        $this->get(route('sales.return', $sale))->assertOk();
    }

    public function test_only_the_owner_sets_the_return_policy(): void
    {
        Livewire::test('settings.index')
            ->call('setTab', 'returns')
            ->set('returnWindowDays', '7')
            ->set('cashRefundsAllowed', false)
            ->call('saveReturnPolicy')
            ->assertHasNoErrors();

        $this->assertSame(7, $this->shop->fresh()->return_window_days);
        $this->assertFalse($this->shop->fresh()->cash_refunds_allowed);

        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Manager', 'permissions' => ['settings', 'settings-categories']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));
        Livewire::test('settings.index')->call('saveReturnPolicy')->assertForbidden();
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** @return array<string, int> account code => paise */
    protected function ledgerBalances(): array
    {
        return DB::table('ledger_lines')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->where('ledger_lines.shop_id', $this->shop->id)
            ->groupBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, SUM(amount_paise) as paise')
            ->pluck('paise', 'code')
            ->map(fn ($paise) => (int) $paise)
            ->all();
    }

    protected function assertHealthy(): void
    {
        app(LedgerPoster::class)->drain();
        $this->assertNull(DB::table('ledger_outbox')->whereNotNull('last_error')->value('last_error'));
        $this->assertSame(0, (int) DB::table('ledger_lines')->where('shop_id', $this->shop->id)->sum('amount_paise'), 'the ledger balances');

        foreach ((new LedgerHealthReport($this->shop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'] ?? 0.0, abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }
}
