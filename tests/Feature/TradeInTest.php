<?php

namespace Tests\Feature;

use App\Actions\Buybacks;
use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\RecordSalePayment;
use App\Actions\StartLedger;
use App\Actions\UpdateSale;
use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerPoster;
use App\Models\Buyback;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\User;
use App\Support\MoneyFlow;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** B2: a used phone handed in as part of paying for a sale. */
class TradeInTest extends TestCase
{
    use RefreshDatabase;

    /** Valid IMEIs (Luhn): the new phone sold, and the old one traded in. */
    protected const NEW_IMEI = '490154203237518';
    protected const OLD_IMEI = '356938035643809';

    protected Shop $shop;
    protected User $owner;
    protected Customer $customer;
    protected Product $newPhone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Mobile Zone', 'business_category' => 'mobile_electronics']);
        MainCategory::ensureDefaultsExist($this->shop->id);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Kamran', 'phone' => '03001234567']);
        $this->newPhone = $this->phoneInStock('Galaxy S23', self::NEW_IMEI, 60000, 70000);
    }

    protected function phoneInStock(string $model, string $imei, float $cost, float $price): Product
    {
        $product = Product::create([
            'shop_id' => $this->shop->id, 'type' => 'mobile', 'name' => 'Samsung '.$model, 'price' => $price, 'cost_price' => $cost,
            'stock_quantity' => 1, 'details' => ['brand' => 'Samsung', 'model' => $model, 'imei' => $imei, 'category' => null],
        ]);
        StockIn::create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'product_name' => $product->name, 'quantity' => 1, 'quantity_remaining' => 1,
            'unit' => 'piece', 'cost_price' => $cost, 'selling_price' => $price, 'total_cost' => $cost, 'amount_paid' => $cost, 'payment_status' => 'paid', 'stock_date' => now()->toDateString(),
        ]);

        return $product;
    }

    protected function tradeIn(array $overrides = []): array
    {
        return $overrides + ['brand' => 'Apple', 'model' => 'iPhone 11', 'imei' => self::OLD_IMEI, 'condition' => 'good', 'price' => 30000, 'selling_price' => 36000, 'seller_cnic' => '3520212345671'];
    }

    protected function sell(?array $tradeIn, float $paid = 40000, ?int $customerId = null, array $installments = []): Sale
    {
        return CreateSale::handle(
            lines: [['product_id' => $this->newPhone->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0,
            customerId: $customerId ?? $this->customer->id,
            userId: $this->owner->id,
            saleDate: now()->toDateString(),
            amountPaid: $paid,
            paymentMethod: 'cash',
            installments: $installments,
            tradeIn: $tradeIn,
        );
    }

    protected function cashIn(): float
    {
        return (float) (new MoneyFlow($this->shop->id))->movements()->sum('amount');
    }

    public function test_a_trade_in_pays_part_of_the_sale_and_brings_the_phone_into_stock(): void
    {
        $sale = $this->sell($this->tradeIn());

        $this->assertSame(70000.0, (float) $sale->total, 'the total is not discounted');
        $this->assertSame(70000.0, $sale->fresh()->amountPaid());
        $this->assertSame(0.0, $sale->fresh()->amountDue());

        $payments = $sale->payments()->orderBy('id')->get();
        $this->assertSame([SalePaymentMethod::Cash, SalePaymentMethod::TradeIn], $payments->pluck('payment_method')->all());
        $this->assertSame([40000.0, 30000.0], $payments->map(fn ($p) => (float) $p->amount)->all());

        $buyback = Buyback::sole();
        $this->assertSame($sale->id, $buyback->sale_id);
        $this->assertSame($payments[1]->id, $buyback->sale_payment_id);
        $this->assertSame($this->customer->id, $buyback->customer_id);
        $this->assertSame('Kamran', $buyback->seller_name, 'the seller defaults to the customer');
        $this->assertStringContainsString('Trade-in: Apple iPhone 11', $payments[1]->methodLabel());

        $traded = Product::findOrFail($buyback->product_id);
        $this->assertEquals(1, $traded->stock_quantity);
        $batch = StockIn::findOrFail($buyback->stock_in_id);
        $this->assertSame('30000.00', $batch->cost_price);
        $this->assertSame('trade_in', $batch->payments()->sole()->payment_method);

        // Only the cash moved: no Rs 30,000 into the drawer, none out of it.
        $this->assertSame(40000.0, $this->cashIn());
    }

    public function test_the_rest_can_be_left_on_udhaar(): void
    {
        $sale = $this->sell($this->tradeIn(), paid: 0);

        $this->assertSame(30000.0, $sale->fresh()->amountPaid());
        $this->assertSame(40000.0, $sale->fresh()->amountDue());
        $this->assertSame(0.0, $this->cashIn());
    }

    public function test_instalments_cover_what_is_left_after_the_trade_in_and_down_payment(): void
    {
        $sale = $this->sell($this->tradeIn(), paid: 10000, installments: [
            ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => 15000],
            ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => 15000],
        ]);

        $this->assertSame(2, $sale->installments()->count());
        $this->assertSame(30000.0, $sale->fresh()->amountDue());
    }

    public function test_a_trade_in_worth_more_than_the_sale_or_the_same_phone_is_refused_and_nothing_is_saved(): void
    {
        foreach ([[$this->tradeIn(['price' => 80000]), 0, 'tradeIn.price'], [$this->tradeIn(), 40001, 'tradeIn.price'], [$this->tradeIn(['imei' => self::NEW_IMEI]), 40000, 'tradeIn.imei']] as [$tradeIn, $paid, $key]) {
            try {
                $this->sell($tradeIn, $paid);
                $this->fail('Expected a refusal.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($key, $e->errors());
            }
        }

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
        $this->assertSame(0, Buyback::count());
        $this->assertEquals(1, $this->newPhone->fresh()->stock_quantity);
    }

    public function test_a_trade_in_cannot_be_cancelled_on_its_own(): void
    {
        $sale = $this->sell($this->tradeIn());

        try {
            Buybacks::cancel(Buyback::sole(), $this->owner->id);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($sale->invoiceNumber(), collect($e->errors())->flatten()->first());
        }

        $this->assertSame(RecordStatus::Active, Buyback::sole()->status);
    }

    public function test_cancelling_the_sale_hands_the_phone_back_and_nothing_is_double_counted(): void
    {
        $sale = $this->sell($this->tradeIn());
        $buyback = Buyback::sole();

        CancelSale::handle($sale, $this->owner->id, 'Customer changed mind');

        $buyback = Buyback::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->id);
        $this->assertSame(RecordStatus::Cancelled, $buyback->status);
        $this->assertEquals(0, Product::withoutGlobalScopes()->findOrFail($buyback->product_id)->stock_quantity);
        $this->assertSame(RecordStatus::Cancelled, StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($buyback->stock_in_id)->status);
        $this->assertEquals(1, $this->newPhone->fresh()->stock_quantity, 'the new phone is back in stock');
        $this->assertSame(0.0, $this->cashIn());
    }

    public function test_the_sale_cannot_be_cancelled_once_the_traded_phone_is_sold_on(): void
    {
        $sale = $this->sell($this->tradeIn());
        $traded = Product::findOrFail(Buyback::sole()->product_id);

        CreateSale::handle(
            lines: [['product_id' => $traded->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0, customerId: null, userId: $this->owner->id, saleDate: now()->toDateString(), amountPaid: 36000, paymentMethod: 'cash',
        );

        try {
            CancelSale::handle($sale, $this->owner->id);
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('cancelReason', $e->errors());
        }

        $this->assertFalse($sale->fresh()->isCancelled());
        $this->assertSame(RecordStatus::Active, Buyback::sole()->status);
        $this->assertEquals(0, $this->newPhone->fresh()->stock_quantity);
    }

    public function test_editing_the_sale_cannot_take_its_total_below_the_trade_in_and_cash(): void
    {
        $sale = $this->sell($this->tradeIn(), paid: 0);

        $line = ['product_id' => $this->newPhone->id, 'product_name' => $this->newPhone->name, 'product_type' => 'mobile', 'quantity' => 1, 'discount' => 0.0];

        try {
            UpdateSale::handle($sale, [$line + ['custom_price' => 20000]], 0.0, $this->customer->id, $this->owner->id, now()->toDateString());
            $this->fail('Expected a refusal.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('total', $e->errors());
        }

        // Down to exactly the trade-in is fine: nothing is left due and the trade-in stays.
        UpdateSale::handle($sale->fresh(), [$line + ['custom_price' => 30000]], 0.0, $this->customer->id, $this->owner->id, now()->toDateString());
        $this->assertSame(0.0, $sale->fresh()->amountDue());
        $this->assertSame(RecordStatus::Active, Buyback::sole()->status);
        $this->assertSame(1, $sale->payments()->where('payment_method', 'trade_in')->count());
    }

    public function test_a_later_payment_cannot_be_a_trade_in(): void
    {
        $sale = $this->sell($this->tradeIn(), paid: 0);

        $this->expectException(ValidationException::class);
        RecordSalePayment::handle($sale, 1000, $this->owner->id, 'trade_in');
    }

    public function test_the_buyback_screen_cannot_record_a_trade_in_on_its_own(): void
    {
        $this->expectException(ValidationException::class);
        Buybacks::record($this->shop, $this->tradeIn() + ['seller_name' => 'X', 'bought_on' => now()->toDateString()], $this->owner->id, 'trade_in', null);
    }

    public function test_the_ledger_balances_and_trade_in_clearing_nets_to_zero_through_a_cancel(): void
    {
        // Already started under LEDGER_FORCE_ON.
        if ($this->shop->fresh()->ledger_started_at === null) {
            StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        }

        // A ledger started before Trade-in Clearing existed gets it on first use.
        DB::table('ledger_accounts')->where('shop_id', $this->shop->id)->where('code', ChartOfAccounts::TRADE_IN)->delete();

        $sale = $this->sell($this->tradeIn());
        app(LedgerPoster::class)->drain();
        $this->assertLedger(0);

        CancelSale::handle($sale->fresh(), $this->owner->id);
        app(LedgerPoster::class)->drain();
        $this->assertLedger(0);
    }

    protected function assertLedger(int $tradeInPaise): void
    {
        $this->assertSame(0, (int) DB::table('ledger_lines')->where('shop_id', $this->shop->id)->sum('amount_paise'), 'the ledger balances');

        $tradeIn = DB::table('ledger_lines')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->where('ledger_lines.shop_id', $this->shop->id)->where('ledger_accounts.code', ChartOfAccounts::TRADE_IN)
            ->sum('amount_paise');

        $this->assertSame($tradeInPaise, (int) $tradeIn);
        $this->assertNull(DB::table('ledger_outbox')->whereNotNull('last_error')->value('last_error'));
    }

    public function test_the_new_sale_screen_takes_a_trade_in(): void
    {
        Livewire::test('sales.create')
            ->call('addToCart', $this->newPhone->id)
            ->set('customerId', (string) $this->customer->id)
            ->set('withTradeIn', true)
            ->set('tradeIn.brand', 'Apple')
            ->set('tradeIn.model', 'iPhone 11')
            ->set('tradeIn.imei', self::OLD_IMEI)
            ->set('tradeIn.price', '30000')
            ->set('tradeIn.selling_price', '36000')
            ->assertSeeHtml('data-trade-in-line')
            ->assertSee('To pay')
            ->call('completeSale')
            ->assertHasNoErrors()
            ->assertRedirect();

        $sale = Sale::sole();
        $this->assertSame(70000.0, $sale->amountPaid(), 'blank amount paid means the rest in full');
        $this->assertSame([40000.0, 30000.0], $sale->payments()->orderBy('id')->pluck('amount')->map(fn ($a) => (float) $a)->all());

        $this->get(route('sales.show', $sale))->assertOk()->assertSee('Trade-in: Apple iPhone 11');
        $this->get(route('buybacks.index'))->assertOk()->assertSee('Trade-in on')->assertSee($sale->invoiceNumber());
    }

    public function test_the_screen_needs_a_seller_for_a_walk_in_and_checks_the_value(): void
    {
        Livewire::test('sales.create')
            ->call('addToCart', $this->newPhone->id)
            ->set('withTradeIn', true)
            ->set('tradeIn.brand', 'Apple')
            ->set('tradeIn.price', '90000')
            ->set('tradeIn.selling_price', '36000')
            ->call('completeSale')
            ->assertHasErrors(['tradeIn.seller_name', 'tradeIn.price']);

        $this->assertSame(0, Sale::withoutGlobalScopes()->count());
    }

    public function test_cancelling_from_the_sale_screen_shows_why_it_is_refused(): void
    {
        $sale = $this->sell($this->tradeIn());
        $traded = Product::findOrFail(Buyback::sole()->product_id);
        CreateSale::handle(
            lines: [['product_id' => $traded->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0, customerId: null, userId: $this->owner->id, saleDate: now()->toDateString(), amountPaid: 36000,
        );

        Livewire::test('sales.show', ['sale' => $sale])
            ->call('cancelSale')
            ->assertHasErrors(['cancelReason']);

        $this->assertFalse($sale->fresh()->isCancelled());
    }

    public function test_shops_that_do_not_sell_devices_get_no_trade_in(): void
    {
        $clothing = Shop::create(['name' => 'Kapra', 'business_category' => 'clothing']);
        $this->actingAs(User::factory()->create(['shop_id' => $clothing->id, 'is_owner' => true]));

        Livewire::test('sales.create')->assertDontSeeHtml('data-trade-in-panel');
    }
}
