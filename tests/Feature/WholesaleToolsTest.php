<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\CustomerPayments;
use App\Actions\RecordSalePayment;
use App\Actions\SaleReturns;
use App\Actions\UpdateSale;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\User;
use App\ReportQueries\DuesAging;
use App\ReportQueries\UdhaarReport;
use App\Support\CreditControl;
use App\Support\PriceTiers;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Wholesale group's P1 tools (W1-W5): lump-sum receipts, business
 * customers, trade prices, credit limits and terms, dues aging, and the
 * performance fix under them, which must leave every dues figure unchanged.
 */
class WholesaleToolsTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday UTC: the shop's day (Karachi) and the server's day agree.
        Carbon::setTestNow('2026-11-20 08:00:00');

        $this->shop = Shop::create(['name' => 'Al-Madina Traders', 'business_category' => 'wholesaler', 'phone' => '042 1112223']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true, 'password' => Hash::make('owner-secret')]);
        $this->actingAs($this->owner);
    }

    protected function product(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Ghee 1kg', 'price' => 100, 'cost_price' => 70,
            'wholesale_price' => 90, 'stock_quantity' => 500, 'details' => ['category' => 'Grocery'],
        ], $attributes));
    }

    protected function customer(array $attributes = []): Customer
    {
        return Customer::create(array_merge(['shop_id' => $this->shop->id, 'name' => 'Bilal', 'business_name' => 'Bilal General Store', 'phone' => '03001234567'], $attributes));
    }

    protected function sell(?Customer $customer, Product $product, int $quantity, float $paid, ?string $date = null, array $attributes = []): Sale
    {
        return CreateSale::handle(
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0, customerId: $customer?->id, userId: $this->owner->id,
            saleDate: $date ?? now()->toDateString(), amountPaid: $paid, paymentMethod: 'cash', attributes: $attributes,
        );
    }

    // ── W4: the dues figures don't move ──────────────────────────────────

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

    // ── Gating: retail shops see none of it ──────────────────────────────

    public function test_retail_shops_see_no_wholesale_fields_and_their_register_lines_are_unchanged(): void
    {
        $shop = Shop::create(['name' => 'Corner Kiryana', 'business_category' => 'kiryana']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Soap', 'price' => 50, 'cost_price' => 30, 'wholesale_price' => 40, 'stock_quantity' => 10, 'details' => ['category' => 'X']]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana']);

        $this->assertFalse($shop->isWholesale());
        $this->get(route('customers.index'))->assertOk()->assertDontSee('Business name')->assertDontSee('Credit limit');
        $this->get(route('customers.show', $customer))->assertOk()->assertDontSee('Receive Payment')->assertDontSee('Credit limit');
        $this->get(route('reports.index'))->assertOk()->assertDontSee('Dues aging');

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id);
        $line = $register->get('cart')[$product->id];
        $this->assertArrayNotHasKey('price_tier', $line);
        $this->assertSame('50', $line['list_price']);
        $register->call('completeSale')->assertHasNoErrors();

        $item = SaleItem::where('product_id', $product->id)->sole();
        $this->assertSame(['50.00', '50.00', null], [$item->unit_price, $item->list_price, $item->price_tier]);
        $this->assertNull(Sale::sole()->due_date);
    }

    // ── W2: business customers ───────────────────────────────────────────

    public function test_business_details_are_saved_and_printed_with_balances_on_the_invoice(): void
    {
        Livewire::test('customers.index')
            ->call('openCreate')
            ->set('name', 'Imran')
            ->set('phone', '03001112223')
            ->set('business_name', 'Imran Kiryana')
            ->set('contact_person', 'Imran Ali')
            ->set('phone_2', '03214445556')
            ->set('area', 'Shah Alam')
            ->set('ntn', '1234567-8')
            ->set('strn', '32-77-8761-234-12')
            ->set('credit_limit', '50000')
            ->set('payment_terms_days', '15')
            ->call('save')
            ->assertHasNoErrors();

        $customer = Customer::sole();
        $this->assertSame(['Imran Kiryana', 'Shah Alam', '1234567-8', 15, '50000.00'], [$customer->business_name, $customer->area, $customer->ntn, $customer->payment_terms_days, $customer->credit_limit]);
        $this->assertSame('Imran Kiryana (Imran)', $customer->displayName());

        $product = $this->product();
        $earlier = $this->sell($customer, $product, 10, 400, '2026-11-01');
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 1000, 'transaction_date' => '2026-11-05']);
        $sale = $this->sell($customer, $product, 5, 100, null, ['due_date' => '2026-12-05']);

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items', 'shop', 'user', 'payments'])])->render();

        $this->assertStringContainsString('Imran Kiryana', $html);
        $this->assertStringContainsString('NTN 1234567-8', $html);
        $this->assertStringContainsString('Shah Alam', $html);
        $this->assertStringContainsString('Payment due by 05 Dec 2026', $html);
        // Sold straight through CreateSale, so at the normal Rs 100. Earlier bill 1,000 - 400 = 600
        // + udhaar 1,000 = 1,600 before; this bill 500 - 100 = 400; 2,000 in all.
        $this->assertStringContainsString('Previous balance', $html);
        $this->assertStringContainsString('Rs 1,600.00', $html);
        $this->assertStringContainsString('Rs 2,000.00', $html);
        $this->assertNotNull($earlier);
    }

    public function test_a_retail_invoice_prints_no_customer_block(): void
    {
        $shop = Shop::create(['name' => 'Phones', 'business_category' => 'mobile_electronics']);
        $owner = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => true]);
        $this->actingAs($owner);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana', 'business_name' => 'Should not print']);
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 300, 'cost_price' => 100, 'stock_quantity' => 5, 'details' => ['category' => 'X']]);
        $sale = CreateSale::handle([['product_id' => $product->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], 0, $customer->id, $owner->id, now()->toDateString(), 0, paymentMethod: 'cash');

        $html = view('pdf.invoice', ['sale' => $sale->fresh(['items', 'shop', 'user', 'payments'])])->render();

        $this->assertStringNotContainsString('Should not print', $html);
        $this->assertStringNotContainsString('Previous balance', $html);
    }

    // ── W3: trade prices ─────────────────────────────────────────────────

    public function test_the_register_prices_a_customer_at_the_trade_price_and_walk_ins_at_the_normal_price(): void
    {
        $product = $this->product();
        $trade = $this->customer();
        $normal = $this->customer(['name' => 'Walk-in regular', 'business_name' => null, 'price_tier' => PriceTiers::NORMAL]);

        $register = Livewire::test('sales.create')->call('addToCart', $product->id);
        $this->assertSame('100', $register->get('cart')[$product->id]['list_price']);

        $register->set('customerId', (string) $trade->id);
        $this->assertSame('90', $register->get('cart')[$product->id]['list_price']);
        $this->assertSame('trade', $register->get('cart')[$product->id]['price_tier']);

        $register->set('customerId', (string) $normal->id);
        $this->assertSame('100', $register->get('cart')[$product->id]['list_price']);

        $register->set('customerId', (string) $trade->id)->set('cart.'.$product->id.'.quantity', 10)->call('completeSale')->assertHasNoErrors();

        $item = SaleItem::sole();
        $this->assertSame(['90.00', '90.00', 'trade', '900.00'], [$item->unit_price, $item->list_price, $item->price_tier, $item->line_total]);
        $this->assertFalse($item->wasPriceOverridden());
        $this->assertSame('Wholesale price', PriceTiers::label($this->shop));
    }

    public function test_a_manual_price_still_wins_over_the_trade_price(): void
    {
        $product = $this->product();
        $customer = $this->customer();

        Livewire::test('sales.create')
            ->set('customerId', (string) $customer->id)
            ->call('addToCart', $product->id)
            ->set('cart.'.$product->id.'.custom_price', '85')
            ->set('customerId', (string) $customer->id)
            ->call('completeSale')
            ->assertHasNoErrors();

        $item = SaleItem::sole();
        $this->assertSame(['85.00', '90.00'], [$item->unit_price, $item->list_price]);
        $this->assertTrue($item->wasPriceOverridden());
    }

    public function test_a_carton_sells_at_the_trade_price_but_never_above_its_own_price(): void
    {
        $product = $this->product(['pack_name' => 'Carton', 'pack_size' => 12, 'pack_price' => 1100]);
        $customer = $this->customer();

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('sellAs', $product->id, 'pack');
        // 12 x 90 = 1,080 is under the carton's own 1,100.
        $this->assertSame('1080', $register->get('cart')[$product->id]['list_price']);
        $register->set('cart.'.$product->id.'.quantity', 2)->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2160.00', Sale::sole()->total);
        $this->assertSame(['Carton', 12], [SaleItem::sole()->pack_name, SaleItem::sole()->pack_size]);

        $cheapCarton = $this->product(['name' => 'Oil', 'pack_name' => 'Carton', 'pack_size' => 12, 'pack_price' => 1000]);
        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $cheapCarton->id)->call('sellAs', $cheapCarton->id, 'pack');
        $this->assertSame('1000', $register->get('cart')[$cheapCarton->id]['list_price']);
    }

    public function test_the_register_shows_the_last_rate_this_customer_paid_and_can_use_it(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        CreateSale::handle([['product_id' => $product->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => 87.5]], 0, $customer->id, $this->owner->id, '2026-11-12', 175, paymentMethod: 'cash');

        $register = Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id);
        $register->assertSee('Last rate')->assertSee('Rs 87.50')->assertSee('12 Nov 2026');

        $register->call('useLastRate', $product->id);
        $this->assertSame('87.5', (string) $register->get('cart')[$product->id]['custom_price']);
    }

    public function test_editing_a_trade_sale_keeps_its_trade_price(): void
    {
        $product = $this->product();
        $customer = $this->customer();
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 3)->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();
        $sale = Sale::sole();

        Livewire::test('sales.edit', ['sale' => $sale])->set('cart.'.$product->id.'.quantity', 4)->call('saveChanges')->assertHasNoErrors();

        $item = SaleItem::where('sale_id', $sale->id)->sole();
        $this->assertSame(['90.00', '90.00', 'trade', '360.00'], [$item->unit_price, $item->list_price, $item->price_tier, $item->line_total]);
    }

    // ── W1: lump-sum receipts ────────────────────────────────────────────

    public function test_a_lump_sum_pays_udhaar_then_the_oldest_bills_and_keeps_the_rest_as_advance(): void
    {
        $product = $this->product(['wholesale_price' => null]);
        $customer = $this->customer();
        $customer->udhaarTransactions()->create(['shop_id' => $this->shop->id, 'user_id' => $this->owner->id, 'type' => 'given', 'amount' => 500, 'transaction_date' => '2026-10-01']);
        $older = $this->sell($customer, $product, 10, 0, '2026-10-05');
        $newer = $this->sell($customer, $product, 20, 500, '2026-11-02');

        Livewire::test('customers.show', ['customer' => $customer])
            ->call('openReceive')
            ->set('receiptAmount', '3500')
            ->assertSee('Kept as udhaar advance')
            ->call('receive')
            ->assertHasNoErrors();

        $receipt = CustomerReceipt::sole();
        $this->assertSame('3500.00', $receipt->amount);
        $this->assertSame([['udhaar', 500.0], ['sale', 1000.0], ['sale', 1500.0], ['advance', 500.0]], array_map(fn ($p) => [$p['source'], (float) $p['amount']], $receipt->applied_to));
        $this->assertSame(0.0, $older->fresh()->amountDue());
        $this->assertSame(0.0, $newer->fresh()->amountDue());
        $this->assertSame(-500.0, $customer->fresh()->udhaarBalance());
        $this->assertSame(500.0, $receipt->advanceAmount());
        $this->assertStringContainsString($receipt->number(), (string) \App\Models\UdhaarTransaction::where('customer_id', $customer->id)->orderByDesc('id')->first()->note);
        $this->assertSame([$this->owner->id, 'cash'], [$older->payments()->latest('id')->first()->user_id, $older->payments()->latest('id')->first()->payment_method?->value ?? $older->payments()->latest('id')->first()->payment_method]);
    }

    public function test_receiving_needs_udhaar_access(): void
    {
        $customer = $this->customer();
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false]);
        $this->actingAs($staff);

        if ($staff->hasAccessTo('udhaar')) {
            $this->markTestSkipped('Default staff role includes udhaar.');
        }

        Livewire::test('customers.show', ['customer' => $customer])->call('openReceive')->assertForbidden();
    }

    // ── W5: credit limit, terms, aging ───────────────────────────────────

    public function test_terms_set_the_due_date_and_a_typed_due_date_wins(): void
    {
        $product = $this->product();
        $customer = $this->customer(['payment_terms_days' => 30]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('amountPaidNow', '0')->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2026-12-20', Sale::latest('id')->first()->due_date->toDateString());

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('amountPaidNow', '0')->set('dueDate', '2026-11-27')->call('completeSale')->assertHasNoErrors();
        $this->assertSame('2026-11-27', Sale::latest('id')->first()->due_date->toDateString());

        // Paid in full: no due date.
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('completeSale')->assertHasNoErrors();
        $this->assertNull(Sale::latest('id')->first()->due_date);
    }

    public function test_the_credit_limit_stops_a_cashier_until_the_owner_approves(): void
    {
        $product = $this->product();
        $customer = $this->customer(['credit_limit' => 1000]);
        $this->sell($customer, $product, 9, 0);
        $staff = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false]);
        $this->actingAs($staff);

        // 9 at the normal Rs 100 (straight through CreateSale) = 900 owed; 3 more at the trade Rs 90 = 270.
        $this->assertSame(['limit' => 1000.0, 'owed' => 900.0, 'after' => 1170.0, 'available' => 100.0, 'over' => true], CreditControl::check($customer, 270));

        $register = fn () => Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 3)->set('amountPaidNow', '0');

        $register()->assertSee('over the credit limit')->call('completeSale')->assertHasErrors('credit');
        $register()->set('overrideCredit', true)->set('overrideReason', 'Regular, pays monthly')->set('ownerPassword', 'wrong')->call('completeSale')->assertHasErrors('ownerPassword');
        $this->assertSame(1, Sale::count());

        $register()->set('overrideCredit', true)->set('overrideReason', 'Regular, pays monthly')->set('ownerPassword', 'owner-secret')->call('completeSale')->assertHasNoErrors();
        $sale = Sale::latest('id')->first();
        $this->assertSame([$this->owner->id, 'Regular, pays monthly'], [$sale->credit_override_by, $sale->credit_override_reason]);

        // Within the limit, or paid now: no question asked.
        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->call('completeSale')->assertHasNoErrors();
    }

    public function test_the_owner_approves_without_a_password(): void
    {
        $product = $this->product();
        $customer = $this->customer(['credit_limit' => 100]);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)->set('cart.'.$product->id.'.quantity', 2)->set('amountPaidNow', '0')
            ->call('completeSale')->assertHasErrors('credit')
            ->set('overrideCredit', true)->set('overrideReason', 'Known customer')->call('completeSale')->assertHasNoErrors();

        $this->assertSame($this->owner->id, Sale::sole()->credit_override_by);
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

        $this->get(route('aging.index'))->assertOk()->assertSee('Bilal General Store (Bilal)')->assertSee('Rs 900.00');
        $this->get(route('aging.index', ['part' => 'udhaar']))->assertOk()->assertSee('Rs 600.00');
        $this->get(route('aging.index', ['area' => 'Elsewhere']))->assertOk()->assertSee('Nothing matches these filters.');
        $this->get(route('reports.index'))->assertSee('Dues aging');
    }

    // ── All three sub-types, start to finish ─────────────────────────────

    public static function subTypes(): array
    {
        return [
            'wholesaler' => ['wholesaler', 'Wholesale price'],
            'trader' => ['trader', 'Wholesale rate'],
            'distributor' => ['distributor', 'Trade price'],
        ];
    }

    /** @dataProvider subTypes */
    public function test_each_sub_type_sells_on_credit_at_its_trade_price_collects_and_ages_dues(string $category, string $label): void
    {
        $this->shop->update(['business_category' => $category]);
        $this->owner = $this->owner->fresh();
        $this->actingAs($this->owner);

        $this->assertSame($label, PriceTiers::label($this->shop->fresh()));
        $this->get(route('customers.index'))->assertOk()->assertSee('Business name');
        $this->get(route('products.index'))->assertOk();

        $product = $this->product();
        $customer = $this->customer(['payment_terms_days' => 7, 'credit_limit' => 5000, 'area' => 'Route 4']);

        Livewire::test('sales.create')->set('customerId', (string) $customer->id)->call('addToCart', $product->id)
            ->assertSee($label)
            ->set('cart.'.$product->id.'.quantity', 20)->set('amountPaidNow', '300')->call('completeSale')->assertHasNoErrors();

        $sale = Sale::sole();
        $this->assertSame(['1800.00', '2026-11-27'], [$sale->total, $sale->due_date->toDateString()]);
        $this->assertSame(1500.0, CreditControl::owed($customer));

        CustomerPayments::receiveAtCounter($customer, 1000, 'cash', null, $this->owner->id);
        $this->assertSame(500.0, $sale->fresh()->amountDue());

        $this->get(route('aging.index', ['area' => 'Route 4']))->assertOk()->assertSee('Rs 500.00');
        $this->get(route('customers.show', $customer))->assertOk()->assertSee('Receive Payment')->assertSee('Rs 5,000.00');
    }
}
