<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\Cheques;
use App\Actions\Contra;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\PurchaseReturns;
use App\Actions\SalesOrders;
use App\Actions\Schemes;
use App\Actions\StartLedger;
use App\Actions\VendorCredits;
use App\Ledger\LedgerPoster;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalesOrder;
use App\Models\Scheme;
use App\Models\SchemeRedemption;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorCredit;
use App\ReportQueries\LedgerHealthReport;
use App\ReportQueries\StockInReport;
use App\Support\MoneyFlow;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Wholesale P2 (W6-W11): cheques, orders and load sheets, routes and bookers,
 * schemes and claims, purchase returns with vendor credit, and contra.
 * Every money test checks dues, vendor owed and Cash & Balances together.
 */
class WholesaleP2Test extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected ShopAccount $bank;

    protected Vendor $vendor;

    protected Product $tea;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 08:00:00');
        $this->useShop('distributor');
    }

    protected function useShop(string $category, array $attributes = []): void
    {
        $this->shop = Shop::create(['name' => 'Al-Noor '.$category, 'business_category' => $category] + $attributes);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('secret')]);
        $this->actingAs($this->owner);
        $this->bank = ShopAccount::create(['shop_id' => $this->shop->id, 'name' => 'Meezan', 'provider_type' => 'Bank']);
        $this->vendor = Vendor::create(['shop_id' => $this->shop->id, 'name' => 'Tapal Distributor']);
        $this->tea = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Tea 950g', 'price' => 1500, 'cost_price' => 1200, 'wholesale_price' => 1400, 'stock_quantity' => 0, 'details' => ['category' => 'Tea', 'brand' => 'Tapal']]);
    }

    protected function stock(int|float $quantity = 100, float $cost = 1200, string $status = 'unpaid', ?string $date = null, ?Product $product = null): StockIn
    {
        return CreateStockIn::handle([['product_id' => ($product ?? $this->tea)->id, 'quantity' => $quantity, 'cost_price' => $cost, 'selling_price' => 1500]], $this->vendor->id, $status, null, $date ?? now()->toDateString(), null, $this->owner->id)->first();
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + ['shop_id' => $this->shop->id, 'name' => 'Bilal', 'business_name' => 'Bilal Store']);
    }

    protected function sell(Customer $customer, int $quantity, float $paid, ?string $date = null, array $extra = []): Sale
    {
        return CreateSale::handle([['product_id' => $this->tea->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null] + $extra], 0, $customer->id, $this->owner->id, $date ?? now()->toDateString(), $paid, paymentMethod: 'cash');
    }

    /** Money in or out of one account on Cash & Balances, all time. */
    protected function moneyIn(?int $accountId = null): float
    {
        return round((float) (new MoneyFlow($this->shop->id))->movements()->filter(fn ($row) => $accountId === null || str_contains((string) $row['bucket'], (string) $accountId))->sum('amount'), 2);
    }

    // ── Gating ─────────────────────────────────────────────────────────────

    public function test_each_trade_gets_its_own_tools_and_retail_gets_none(): void
    {
        $tools = fn (string $category) => \App\Enums\BusinessCategory::from($category)->wholesaleTools();

        $this->assertSame(['cheques', 'returns', 'bonus'], $tools('wholesaler'));
        $this->assertSame(['cheques', 'returns', 'bonus', 'contra'], $tools('trader'));
        $this->assertSame(['cheques', 'returns', 'bonus', 'orders', 'routes', 'schemes'], $tools('distributor'));
        $this->assertSame([], $tools('kiryana'));
        $this->assertSame([], $tools('mobile_electronics'));

        $this->get(route('dashboard'))->assertSee('Orders')->assertSee('Cheques')->assertSee('Schemes');
        $this->get(route('cheques.index'))->assertOk();
        $this->get(route('sales-orders.index'))->assertOk();
        $this->get(route('load-sheets.index'))->assertOk();
        $this->get(route('schemes.index'))->assertOk();
        $this->get(route('delivery-routes.index'))->assertOk();
        $this->get(route('purchase-returns.index'))->assertOk();
        $this->get(route('purchase-returns.create'))->assertOk();
        $this->get(route('sales-performance.index'))->assertOk();
        $this->get(route('sales-orders.create'))->assertOk();

        $this->useShop('wholesaler');
        $this->get(route('cheques.index'))->assertOk();
        $this->get(route('sales-orders.index'))->assertNotFound();
        $this->get(route('schemes.index'))->assertNotFound();

        $this->useShop('kiryana');
        foreach (['cheques.index', 'sales-orders.index', 'schemes.index', 'purchase-returns.index', 'delivery-routes.index', 'sales-performance.index', 'load-sheets.index'] as $name) {
            $this->get(route($name))->assertNotFound();
        }
        $this->get(route('dashboard'))->assertDontSee('Cheques')->assertDontSee('Schemes');
    }

    // ── W6: cheques ────────────────────────────────────────────────────────

    public function test_a_received_cheque_settles_nothing_until_it_clears_and_a_bounce_brings_the_dues_back(): void
    {
        $this->stock(50);
        $customer = $this->customer();
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-11-01']);
        $older = $this->sell($customer, 2, 0, '2026-11-02');      // 3,000 owed
        $newer = $this->sell($customer, 3, 500, '2026-11-10');    // 4,000 owed
        $flowBefore = $this->moneyIn();

        $cheque = Cheques::record(['direction' => 'received', 'party_id' => $customer->id, 'cheque_number' => '004512', 'bank' => 'HBL', 'amount' => 5000, 'cheque_date' => '2026-11-30', 'received_on' => '2026-11-20'], $this->owner->id);
        $this->assertSame(1000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(7000.0, $customer->fresh()->salesOutstandingBalance());
        $this->assertSame(5000.0, Cheques::inHand($customer->id));

        Cheques::deposit($cheque, $this->bank->id);
        Cheques::clear($cheque->fresh(), null, $this->owner->id);

        $this->assertSame('cleared', $cheque->fresh()->status);
        $this->assertSame(0.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(0.0, $older->fresh()->amountDue());
        $this->assertSame(3000.0, $newer->fresh()->amountDue());
        $this->assertSame(round($flowBefore + 5000, 2), $this->moneyIn());
        $this->assertSame(0.0, Cheques::inHand($customer->id));

        Carbon::setTestNow('2026-12-02 08:00:00');
        Cheques::bounce($cheque->fresh(), 'Insufficient funds', $this->owner->id);

        $this->assertSame('bounced', $cheque->fresh()->status);
        $this->assertSame(1000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(3000.0, $older->fresh()->amountDue());
        $this->assertSame(4000.0, $newer->fresh()->amountDue());
        $this->assertSame($flowBefore, $this->moneyIn());
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
        $this->assertSame(-3000.0, $this->moneyIn());   // the 500 extra went to the vendor too; it's credit, not cash flow
        $this->assertSame(['paid', 'paid'], [$first->fresh()->payment_status->value, $second->fresh()->payment_status->value]);

        Cheques::bounce($cheque->fresh(), 'Stopped', $this->owner->id);
        $this->assertSame(3000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(0.0, VendorCredits::available($this->vendor->id));
        $this->assertSame(0.0, $this->moneyIn());
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
        $this->get(route('aging.index'))->assertOk()->assertSee('Cheques in hand');
    }

    // ── W7: orders, load sheets, challans ──────────────────────────────────

    public function test_a_booked_order_holds_its_stock_until_delivery(): void
    {
        $this->stock(10);
        $customer = $this->customer(['payment_terms_days' => 15]);
        $order = SalesOrders::save(null, $customer->id, [['product_id' => $this->tea->id, 'quantity' => 8, 'unit_price' => 1400, 'price_tier' => 'trade']], $this->owner->id, '2026-11-21', null, $this->owner->id);

        $this->assertSame(10, $this->tea->fresh()->stock_quantity);
        $this->assertSame(2, SalesOrders::available($this->tea->fresh()));

        try {
            SalesOrders::save(null, $customer->id, [['product_id' => $this->tea->id, 'quantity' => 3, 'unit_price' => 1400]], null, null, null, $this->owner->id);
            $this->fail('Booking past free stock must be refused.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('2 of Tea 950g is free to book', collect($e->errors())->flatten()->first());
        }

        $walkIn = Livewire::test('sales.create')->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 3)->call('completeSale');
        $walkIn->assertHasErrors('cart.'.$this->tea->id.'.quantity');
        $this->assertSame(0, Sale::count());

        $line = $order->lines->first();
        $sale = SalesOrders::deliver($order, [$line->id => 6], 3000, 'cash', null, $this->owner->id);

        $this->assertSame(SalesOrder::DELIVERED, $order->fresh()->status);
        $this->assertSame($sale->id, $order->fresh()->sale_id);
        $this->assertSame('8400.00', $sale->total);
        $this->assertSame(5400.0, $sale->amountDue());
        $this->assertSame('2026-12-05', $sale->due_date->toDateString());
        $this->assertSame($this->owner->id, $sale->booker_id);
        $item = SaleItem::where('sale_id', $sale->id)->first();
        $this->assertSame(['1400.00', '1400.00', 'trade'], [$item->unit_price, $item->list_price, $item->price_tier]);
        $this->assertSame(4, $this->tea->fresh()->stock_quantity);
        $this->assertSame(4, SalesOrders::available($this->tea->fresh()));
    }

    public function test_a_load_sheet_lists_what_to_load_and_settles_once_every_stop_is_done(): void
    {
        $this->stock(100);
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Shah Alam Mon', 'visit_days' => ['mon']]);
        $first = $this->customer(['name' => 'Second stop', 'business_name' => null, 'route_id' => $route->id, 'route_sequence' => 2]);
        $second = $this->customer(['name' => 'First stop', 'business_name' => null, 'route_id' => $route->id, 'route_sequence' => 1]);
        $a = SalesOrders::save(null, $first->id, [['product_id' => $this->tea->id, 'quantity' => 12, 'unit_price' => 1400, 'pack_name' => 'Carton', 'pack_size' => 12]], null, null, null, $this->owner->id);
        $b = SalesOrders::save(null, $second->id, [['product_id' => $this->tea->id, 'quantity' => 5, 'unit_price' => 1450]], null, null, null, $this->owner->id);

        Livewire::test('load-sheets.index')->set('routeId', (string) $route->id)->assertSet('orderIds', [(string) $a->id, (string) $b->id])
            ->set('vehicle', 'LES-4512')->call('create')->assertHasNoErrors();
        $sheet = \App\Models\LoadSheet::sole();

        $this->assertSame([['product' => 'Tea 950g', 'quantity' => 17]], array_map(fn ($row) => ['product' => $row['product'], 'quantity' => $row['quantity']], SalesOrders::loadingList($sheet)));
        $this->get(route('load-sheets.show', $sheet))->assertOk()->assertSeeInOrder(['1. First stop', '2. Second stop']);

        $page = Livewire::test('load-sheets.show', ['sheet' => $sheet])->call('settle')->assertHasErrors('settle');
        SalesOrders::deliver($a->fresh(), [], 0, null, null, $this->owner->id);
        $page->call('bringBack', $b->id)->call('settle')->assertHasNoErrors();

        $this->assertSame('settled', $sheet->fresh()->status);
        $this->assertSame([SalesOrder::BOOKED, null], [$b->fresh()->status, $b->fresh()->load_sheet_id]);

        $html = view('pdf.delivery-challan', ['order' => $a->fresh(['lines', 'customer', 'sale.items', 'loadSheet', 'booker']), 'shop' => $this->shop])->render();
        $this->assertStringContainsString('Delivery Challan', $html);
        $this->assertStringContainsString('LES-4512', $html);
        $sheetHtml = view('pdf.load-sheet', ['sheet' => $sheet->fresh(['route', 'salesman']), 'loading' => [], 'stops' => collect([$a->fresh('customer')]), 'owed' => [], 'shop' => $this->shop])->render();
        $this->assertStringContainsString('Load Sheet', $sheetHtml);
    }

    public function test_the_order_screens_book_and_deliver(): void
    {
        $this->stock(50);
        $customer = $this->customer();

        Livewire::test('sales-orders.form')->set('customerId', (string) $customer->id)->call('add', $this->tea->id)
            ->set('lines.0.quantity', '4')->call('save')->assertHasNoErrors();

        $order = SalesOrder::sole();
        $this->assertSame('5600.00', $order->estimated_total);   // trade price 1,400

        Livewire::test('sales-orders.show', ['order' => $order])->call('openDeliver')->set('collected', '5600')->call('deliver')->assertHasNoErrors();
        $this->assertSame(0.0, $order->fresh()->sale->amountDue());
    }

    // ── W8: routes, bookers, report ────────────────────────────────────────

    public function test_the_register_records_the_booker_and_the_report_splits_by_booker_route_and_brand(): void
    {
        $this->stock(50);
        $booker = User::factory()->create(['shop_id' => $this->shop->id, 'name' => 'Asif Booker']);
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Route 7', 'booker_id' => $booker->id]);
        $customer = $this->customer(['route_id' => $route->id]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->assertSet('bookerId', (string) $booker->id)
            ->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 2)->call('completeSale')->assertHasNoErrors();
        $this->assertSame($booker->id, Sale::sole()->booker_id);
        $this->get(route('sales.show', Sale::sole()))->assertSee('Asif Booker');

        $this->get(route('sales-performance.index'))->assertOk()->assertSee('Asif Booker')->assertSee('Rs 2,800.00')->assertSee('Rs 400.00');
        $this->get(route('sales-performance.index', ['by' => 'route']))->assertOk()->assertSee('Route 7');
        $this->get(route('sales-performance.index', ['by' => 'brand']))->assertOk()->assertSee('Tapal');
    }

    public function test_routes_keep_customers_in_visiting_order(): void
    {
        $route = DeliveryRoute::create(['shop_id' => $this->shop->id, 'name' => 'Route 1']);
        $a = $this->customer(['name' => 'A']);
        $b = $this->customer(['name' => 'B']);

        Livewire::test('delivery-routes.index')->set('routeId', $route->id)
            ->set('addCustomerId', (string) $a->id)->call('addCustomer')
            ->set('addCustomerId', (string) $b->id)->call('addCustomer')
            ->call('move', $b->id, -1);

        $this->assertSame([$b->id, $a->id], $route->customers()->pluck('id')->all());
    }

    // ── W9: schemes, bonus stock, claims ───────────────────────────────────

    public function test_a_scheme_gives_free_pieces_at_the_register_and_records_them_for_the_claim(): void
    {
        $this->stock(100, 1200);
        Scheme::create(['shop_id' => $this->shop->id, 'name' => '10+1', 'product_id' => $this->tea->id, 'vendor_id' => $this->vendor->id, 'buy_quantity' => 10, 'free_quantity' => 1, 'starts_on' => '2026-11-01']);
        $customer = $this->customer();

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $this->tea->id)->set('cart.'.$this->tea->id.'.quantity', 20);
        $register->assertSee('Scheme 10 + 1 free: +2 free');
        $register->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame('28000.00', $sale->total);   // 20 x trade 1,400; the 2 free cost nothing
        $this->assertSame(78, $this->tea->fresh()->stock_quantity);
        $this->assertSame(22, (int) SaleItem::where('sale_id', $sale->id)->sum('quantity'));
        $redemption = SchemeRedemption::sole();
        $this->assertSame(['2', '2400.00'], [(string) $redemption->free_quantity, $redemption->cost_value]);

        // A scheme sale can't be edited (its claim is per sale): no Edit button, and UpdateSale refuses.
        $this->get(route('sales.show', $sale))->assertOk()->assertDontSee(route('sales.edit', $sale));
        $this->expectException(ValidationException::class);
        \App\Actions\UpdateSale::handle($sale, [], 0, $customer->id, $this->owner->id, '2026-11-20');
    }

    public function test_bonus_stock_spreads_the_cost_and_owes_only_what_was_bought(): void
    {
        Livewire::test('stock-ins.create')
            ->set('productId', (string) $this->tea->id)->set('quantity', '10')->set('costPrice', '1200')->set('sellingPrice', '1500')->set('freeQuantity', '2')
            ->call('addLine')->set('vendorId', (string) $this->vendor->id)->set('paymentStatus', 'unpaid')->call('save')->assertHasNoErrors();

        $batch = StockIn::sole();
        $this->assertSame([12, '12000.00', '1000.00', '2.000'], [$batch->quantity, $batch->total_cost, $batch->cost_price, (string) $batch->getRawOriginal('free_quantity')]);
        $this->assertSame(12000.0, $this->vendor->totalOwed());
        $this->assertSame(12, $this->tea->fresh()->stock_quantity);
    }

    public function test_free_goods_are_claimed_from_the_company_and_settle_as_credit_or_money(): void
    {
        $this->stock(200, 1200, 'unpaid', '2026-11-01');
        $scheme = Scheme::create(['shop_id' => $this->shop->id, 'name' => '10+1', 'product_id' => $this->tea->id, 'vendor_id' => $this->vendor->id, 'buy_quantity' => 10, 'free_quantity' => 1, 'starts_on' => '2026-11-01']);
        $customer = $this->customer();
        $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        $this->sell($customer, 20, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 2]);
        $cancelled = $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        CancelSale::handle($cancelled->fresh(), $this->owner->id, 'x');

        $claim = Schemes::claim($this->vendor->id, null, null, 'November', $this->owner->id);
        $this->assertSame('3600.00', $claim->claimed_amount);   // 3 free x 1,200, the cancelled sale left out

        $html = view('pdf.scheme-claim', ['claim' => $claim->fresh(['vendor', 'redemptions.scheme', 'redemptions.sale.customer']), 'shop' => $this->shop])->render();
        $this->assertStringContainsString($claim->number(), $html);

        $owed = $this->vendor->totalOwed();
        Schemes::settle($claim, 3000, 'credit', null, null, $this->owner->id);   // short by 600
        $this->assertSame(round($owed - 3000, 2), $this->vendor->fresh()->totalOwed());
        $this->assertSame(0.0, $this->moneyIn());

        $this->sell($customer, 10, 0, null, ['scheme_id' => $scheme->id, 'scheme_free' => 1]);
        $second = Schemes::claim($this->vendor->id, null, null, null, $this->owner->id);
        Schemes::settle($second, 1200, 'cash', 'account', $this->bank->id, $this->owner->id);
        $this->assertSame(1200.0, $this->moneyIn());

        Livewire::test('schemes.index', ['tab' => 'claims'])->assertSee($claim->number())->assertSee('Short paid');
    }

    // ── W10: purchase returns ──────────────────────────────────────────────

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
        $this->assertSame(0.0, $this->moneyIn() + 10000.0);   // no money moved: only the first batch's payment

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
        $this->assertSame(round(-10000 + 1000, 2), $this->moneyIn());

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

    // ── W11: contra ────────────────────────────────────────────────────────

    public function test_contra_sets_bills_off_against_what_the_shop_owes_and_never_touches_udhaar(): void
    {
        $this->useShop('trader');
        $this->stock(10, 1000, 'unpaid');   // shop owes the vendor 10,000
        $customer = $this->customer(['vendor_id' => $this->vendor->id]);
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 2000, 'transaction_date' => '2026-11-01']);
        $sale = $this->sell($customer, 4, 0);   // they owe 4 x 1,500 = 6,000 on bills
        $flow = $this->moneyIn();

        $this->assertSame(['customer_owes' => 6000.0, 'vendor_owed' => 10000.0, 'most' => 6000.0], Contra::position($customer->fresh()));

        try {
            Contra::settle($customer, 6500, null, $this->owner->id);
            $this->fail('More than the smaller side must be refused.');
        } catch (ValidationException) {
        }

        Livewire::test('customers.show', ['customer' => $customer])->assertSee('Contra with')->call('openContra')->set('contraAmount', '5000')->call('settleContra')->assertHasNoErrors();

        $this->assertSame(1000.0, $sale->fresh()->amountDue());
        $this->assertSame(5000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame(2000.0, $customer->fresh()->udhaarBalance());
        $this->assertSame($flow, $this->moneyIn());

        Contra::cancel(\App\Models\ContraSettlement::sole(), $this->owner->id);
        $this->assertSame(6000.0, $sale->fresh()->amountDue());
        $this->assertSame(10000.0, $this->vendor->fresh()->totalOwed());
        $this->assertSame($flow, $this->moneyIn());
    }

    public function test_the_customer_form_links_a_vendor_once(): void
    {
        $this->useShop('trader');
        $customer = $this->customer();
        $other = $this->customer(['name' => 'Other']);

        Livewire::test('customers.index')->call('openEdit', $customer->id)->set('linkedVendorId', (string) $this->vendor->id)->call('save')->assertHasNoErrors();
        $this->assertSame($this->vendor->id, $customer->fresh()->vendor_id);

        Livewire::test('customers.index')->call('openEdit', $other->id)->set('linkedVendorId', (string) $this->vendor->id)->call('save')->assertHasErrors('linkedVendorId');
        $this->get(route('vendors.show', $this->vendor))->assertSee('Also a customer');
    }

    // ── The ledger stays healthy through all of it ─────────────────────────

    public function test_the_ledger_balances_and_agrees_with_the_records_through_every_p2_flow(): void
    {
        $this->shop = StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
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
