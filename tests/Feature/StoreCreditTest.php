<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\RecordSalePayment;
use App\Actions\SaleReturns;
use App\Actions\StartLedger;
use App\Actions\StoreCredit;
use App\Enums\SalePaymentMethod;
use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerPoster;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Shop;
use App\Models\StoreCreditEntry;
use App\Models\User;
use App\ReportQueries\LedgerHealthReport;
use App\Support\BatchExpiry;
use App\Support\MoneyFlow;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R2: store credit. Given on a return, spent on later sales soonest-expiring
 * first, paid out as money only where the shop gives money back, written off
 * after its expiry date, and kept apart from udhaar throughout.
 */
class StoreCreditTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected Customer $customer;

    protected Product $kurta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Libas', 'business_category' => 'clothing']);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha', 'phone' => '03001234567']);
        $this->actingAs($this->owner);

        $this->kurta = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Lawn Kurta', 'price' => 2000, 'stock_quantity' => 0, 'details' => ['category' => null]]);
        CreateStockIn::handle([['product_id' => $this->kurta->id, 'quantity' => 20, 'cost_price' => 1000, 'selling_price' => 2000]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
    }

    protected function sell(int $quantity, float $paid, ?int $customerId = -1, float $credit = 0.0): Sale
    {
        return CreateSale::handle(
            lines: [['product_id' => $this->kurta->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0,
            customerId: $customerId === -1 ? $this->customer->id : $customerId,
            userId: $this->owner->id,
            saleDate: now()->toDateString(),
            amountPaid: $paid,
            paymentMethod: 'cash',
            storeCredit: $credit,
        );
    }

    /** A fully paid sale of $quantity, returned to store credit. */
    protected function credit(int $quantity = 1, ?int $customerId = -1): SaleReturn
    {
        $sale = $this->sell($quantity, $quantity * 2000, $customerId);

        return SaleReturns::create($sale, ['p'.$this->kurta->id => ['quantity' => (string) $quantity]], null, null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CREDIT, creditCustomerId: $customerId === null ? $this->customer->id : null);
    }

    protected function cashMoves(string $source): float
    {
        return (float) (new MoneyFlow($this->shop->id))->movements()->where('source', $source)->sum('amount');
    }

    // ── Issued on a return ─────────────────────────────────────────────

    public function test_a_return_to_store_credit_hands_back_no_money_and_the_customer_holds_it(): void
    {
        $return = $this->credit(2);

        $this->assertSame(4000.0, (float) $return->refund_credit);
        $this->assertSame(0.0, (float) $return->refund_cash);
        $this->assertSame(4000.0, StoreCredit::balance($this->customer->id));
        $this->assertSame(0.0, $this->cashMoves('refund'), 'nothing left the till');
        $this->assertSame(0.0, $this->customer->udhaarBalance(), 'kept apart from udhaar');

        $issue = StoreCreditEntry::sole();
        $this->assertSame($return->id, $issue->sale_return_id);
        $this->assertNull($issue->expires_on, 'never expires by default');
    }

    public function test_a_walk_in_return_needs_a_customer_for_its_credit(): void
    {
        $sale = $this->sell(1, 2000, null);
        $line = ['p'.$this->kurta->id => ['quantity' => '1']];

        $this->assertThrows(fn () => SaleReturns::create($sale, $line, null, null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CREDIT), ValidationException::class, 'Choose the customer');

        $other = Customer::create(['shop_id' => Shop::create(['name' => 'Other'])->id, 'name' => 'Not ours']);
        $this->assertThrows(fn () => SaleReturns::create($sale, $line, null, null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CREDIT, creditCustomerId: $other->id), ValidationException::class);

        $return = SaleReturns::create($sale, $line, null, null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CREDIT, creditCustomerId: $this->customer->id);
        $this->assertSame($this->customer->id, $return->customer_id);
        $this->assertSame(2000.0, StoreCredit::balance($this->customer->id));
    }

    public function test_credit_expiry_follows_the_shop_setting_at_the_time_it_was_given(): void
    {
        $this->shop->update(['store_credit_expiry_days' => 30]);
        $this->credit();

        $this->assertSame(CarbonImmutable::parse(BatchExpiry::today())->addDays(30)->toDateString(), StoreCreditEntry::sole()->expires_on->toDateString());
    }

    // ── Spending ───────────────────────────────────────────────────────

    public function test_credit_pays_part_of_a_sale_without_moving_money(): void
    {
        $this->credit(2);
        $cashBefore = $this->cashMoves('sale');

        $sale = $this->sell(3, 2000, credit: 4000);

        $this->assertSame(0.0, $sale->fresh()->amountDue());
        $this->assertSame([SalePaymentMethod::Cash, SalePaymentMethod::StoreCredit], $sale->payments()->orderBy('id')->pluck('payment_method')->all());
        $this->assertSame(0.0, StoreCredit::balance($this->customer->id));
        $this->assertSame(2000.0, $this->cashMoves('sale') - $cashBefore, 'only the money part is cash');
        $this->assertSame('Store credit', $sale->payments()->where('payment_method', 'store_credit')->sole()->methodLabel());
    }

    public function test_spending_more_than_is_held_or_than_the_sale_is_refused(): void
    {
        $this->credit();

        $this->assertThrows(fn () => $this->sell(2, 0, credit: 2500), ValidationException::class, 'Only Rs 2,000.00');
        $this->assertThrows(fn () => $this->sell(1, 1000, credit: 1500), ValidationException::class, 'more than the sale');
        $this->assertThrows(fn () => $this->sell(1, 0, null, credit: 500), ValidationException::class);
        $this->assertSame(2000.0, StoreCredit::balance($this->customer->id));
        $this->assertSame(1, Sale::count(), 'refused sales were rolled back');
    }

    public function test_spending_draws_the_soonest_expiring_credit_first_and_never_expiring_last(): void
    {
        $forever = $this->credit();
        $this->shop->update(['store_credit_expiry_days' => 10]);
        $soon = $this->credit();

        $this->sell(1, 500, credit: 1500);

        $this->assertSame([500.0, 2000.0], [
            (float) StoreCreditEntry::where('sale_return_id', $soon->id)->sole()->remaining,
            (float) StoreCreditEntry::where('sale_return_id', $forever->id)->sole()->remaining,
        ]);
    }

    public function test_cancelling_the_sale_gives_the_credit_back(): void
    {
        $this->credit();
        $sale = $this->sell(1, 0, credit: 2000);

        CancelSale::handle($sale->fresh(), $this->owner->id);

        $this->assertSame(2000.0, StoreCredit::balance($this->customer->id));
        $this->assertSame(2000.0, StoreCredit::spendable($this->customer->id));
    }

    public function test_record_payment_can_use_store_credit(): void
    {
        $this->credit();
        $sale = $this->sell(1, 0);

        Livewire::test('sales.history')
            ->call('openRecordPayment', $sale->id)
            ->assertSee('Store credit · Rs 2,000.00 available')
            ->set('paymentAmount', '2500')
            ->set('paymentMethod', 'store_credit')
            ->call('submitPayment')
            ->assertHasErrors('paymentAmount')
            ->set('paymentAmount', '1500')
            ->call('submitPayment')
            ->assertHasNoErrors();

        $this->assertSame(500.0, $sale->fresh()->amountDue());
        $this->assertSame(500.0, StoreCredit::balance($this->customer->id));

        // The generic path can't create one without drawing on credit.
        $this->assertThrows(fn () => SalePaymentMethod::paymentAttributes('store_credit', null, $this->shop->id, 10), ValidationException::class);
    }

    public function test_the_register_offers_the_customers_credit_and_takes_it_off_what_is_left_to_pay(): void
    {
        $this->credit();

        Livewire::test('sales.create')
            ->call('addToCart', $this->kurta->id)
            ->call('addToCart', $this->kurta->id)
            ->assertDontSeeHtml('data-store-credit-panel')
            ->set('customerId', (string) $this->customer->id)
            ->assertSeeHtml('data-store-credit-panel')
            ->set('useCredit', true)
            ->assertSeeHtml('data-credit-line')
            ->call('completeSale')
            ->assertHasNoErrors();

        $sale = Sale::latest('id')->first();
        $this->assertSame(4000.0, (float) $sale->total);
        $this->assertSame(2000.0, (float) $sale->payments()->where('payment_method', 'store_credit')->sum('amount'));
        $this->assertSame(2000.0, (float) $sale->payments()->where('payment_method', 'cash')->sum('amount'));
        $this->assertSame(0.0, StoreCredit::balance($this->customer->id));
    }

    // ── Expiry ─────────────────────────────────────────────────────────

    public function test_expired_credit_cannot_be_spent_and_the_daily_task_writes_it_off(): void
    {
        $this->shop->update(['store_credit_expiry_days' => 5]);
        $return = $this->credit();
        $issue = StoreCreditEntry::sole();
        $issue->update(['expires_on' => now()->subDays(2)->toDateString()]);

        $this->assertSame(0.0, StoreCredit::spendable($this->customer->id));
        $this->assertSame(2000.0, StoreCredit::balance($this->customer->id), 'still held until written off');
        $this->assertThrows(fn () => $this->sell(1, 0, credit: 500), ValidationException::class);

        $this->artisan('credits:expire')->assertSuccessful();

        $expiry = StoreCreditEntry::where('type', 'expire')->sole();
        $this->assertSame(-2000.0, (float) $expiry->amount);
        $this->assertSame(now()->subDay()->toDateString(), $expiry->entry_date->toDateString());
        $this->assertSame(0.0, StoreCredit::balance($this->customer->id));

        // Running it again writes off nothing more; the return can no longer be cancelled.
        $this->artisan('credits:expire');
        $this->assertSame(1, StoreCreditEntry::where('type', 'expire')->count());
        $this->assertThrows(fn () => SaleReturns::cancel($return, $this->owner->id), ValidationException::class, 'already been used');
    }

    // ── Cancelling the return ──────────────────────────────────────────

    public function test_a_return_whose_credit_was_spent_cannot_be_cancelled_until_the_spend_is(): void
    {
        $return = $this->credit();
        $sale = $this->sell(1, 1000, credit: 1000);

        $this->assertThrows(fn () => SaleReturns::cancel($return, $this->owner->id), ValidationException::class, 'Rs 1,000.00 of the store credit');
        $this->assertFalse($return->fresh()->isCancelled());

        CancelSale::handle($sale->fresh(), $this->owner->id);
        SaleReturns::cancel($return, $this->owner->id);

        $this->assertTrue($return->fresh()->isCancelled());
        $this->assertSame(0.0, StoreCredit::balance($this->customer->id));
    }

    // ── Paying credit out ──────────────────────────────────────────────

    public function test_credit_can_be_paid_out_as_money_and_shows_as_its_own_movement(): void
    {
        $this->credit();

        Livewire::test('customers.show', ['customer' => $this->customer])
            ->assertSeeHtml('data-store-credit')
            ->call('openCashOut')
            ->assertSet('cashOutAmount', '2000')
            ->set('cashOutAmount', '1200')
            ->set('cashOutNote', 'Moving abroad')
            ->call('cashOut')
            ->assertHasNoErrors();

        $this->assertSame(800.0, StoreCredit::balance($this->customer->id));
        $this->assertSame(-1200.0, $this->cashMoves('credit'));
        $this->assertSame(0.0, $this->cashMoves('refund'), 'not counted as a return refund');

        $row = (new MoneyFlow($this->shop->id))->movements()->firstWhere('source', 'credit');
        $this->assertSame('Store credit paid out: Ayesha', $row['label']);
        $this->assertSame('Cashed out from Cash', StoreCreditEntry::where('type', 'cash_out')->sole()->describe());

        $this->assertThrows(fn () => StoreCredit::cashOut($this->customer, 900, 'cash', null, $this->owner->id), ValidationException::class, 'Only Rs 800.00');
    }

    public function test_credit_cannot_be_paid_out_where_the_shop_gives_no_money_back(): void
    {
        $this->credit();
        $this->shop->update(['cash_refunds_allowed' => false]);

        $this->assertThrows(fn () => StoreCredit::cashOut($this->customer->fresh(), 500, 'cash', null, $this->owner->id), ValidationException::class, 'can only be spent');
        Livewire::test('customers.show', ['customer' => $this->customer])->assertDontSee('Pay Out as Money')->call('openCashOut')->assertForbidden();
    }

    public function test_staff_need_the_returns_permission_to_pay_credit_out(): void
    {
        $this->credit();
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['customers', 'sales']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));

        Livewire::test('customers.show', ['customer' => $this->customer])->assertDontSee('Pay Out as Money')->call('cashOut')->assertForbidden();
    }

    // ── Screens and statements ──────────────────────────────────────────

    public function test_the_return_screen_sends_the_rest_to_credit_and_asks_for_a_customer_on_a_walk_in_sale(): void
    {
        $sale = $this->sell(2, 4000, null);

        Livewire::test('sales.return', ['sale' => $sale])
            ->set('quantities.p'.$this->kurta->id, '1')
            ->set('refundTo', 'credit')
            ->assertSee('Store credit is kept against a customer')
            ->call('save')
            ->assertHasErrors('creditCustomerId')
            ->set('creditCustomerId', (string) $this->customer->id)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2000.0, StoreCredit::balance($this->customer->id));

        Livewire::test('sales.show', ['sale' => $sale])->assertSee('Rs 2,000.00 given as store credit to Ayesha');
    }

    public function test_a_shop_without_money_refunds_only_offers_credit_on_the_return_screen(): void
    {
        $this->shop->update(['cash_refunds_allowed' => false]);
        $sale = $this->sell(1, 2000);

        Livewire::test('sales.return', ['sale' => $sale])
            ->set('quantities.p'.$this->kurta->id, '1')
            ->assertSeeHtml('data-no-cash-refunds')
            ->assertDontSee('Hand back money')
            ->set('refundTo', 'cash')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2000.0, (float) SaleReturn::sole()->refund_credit, 'credit even when the form was tampered with');
    }

    public function test_the_customer_statement_has_a_separate_store_credit_section(): void
    {
        $this->credit(2);
        $this->sell(1, 0, credit: 2000);

        Livewire::withQueryParams(['party' => 'customer', 'customer' => $this->customer->id])
            ->test('party-ledger.index')
            ->assertSee('Store credit')
            ->assertSee('Issued on return RN-')
            ->assertSee('Spent on INV-');

        // A customer with no credit history gets no such section.
        $other = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Bilal']);
        Livewire::withQueryParams(['party' => 'customer', 'customer' => $other->id])->test('party-ledger.index')->assertDontSee('Given on returns (credit)');
    }

    public function test_the_store_credit_report_shows_what_was_given_and_who_holds_it(): void
    {
        $this->credit(2);
        $this->sell(1, 0, credit: 1500);

        Livewire::test('store-credit.index')
            ->assertSee('Ayesha')
            ->assertSee('Rs 2,500.00')
            ->assertSee('Rs 4,000.00')
            ->assertSee('Rs 1,500.00');

        $this->get(route('store-credit.index'))->assertOk();
    }

    public function test_the_owner_sets_how_long_credit_lasts(): void
    {
        Livewire::test('settings.index')
            ->call('setTab', 'returns')
            ->set('storeCreditExpiryDays', '90')
            ->call('saveReturnPolicy')
            ->assertHasNoErrors();

        $this->assertSame(90, $this->shop->fresh()->store_credit_expiry_days);

        Livewire::test('settings.index')->call('setTab', 'returns')->set('storeCreditExpiryDays', '')->call('saveReturnPolicy');
        $this->assertNull($this->shop->fresh()->store_credit_expiry_days);
    }

    public function test_a_customer_with_store_credit_has_financial_history(): void
    {
        $this->credit();

        $this->assertTrue($this->customer->hasFinancialHistory());
    }

    // ── Ledger ──────────────────────────────────────────────────────────

    public function test_the_ledger_carries_store_credit_as_owed_to_the_customer_through_spend_pay_out_and_expiry(): void
    {
        if ($this->shop->fresh()->ledger_started_at === null) {
            StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        }

        // Started before these accounts existed: they appear on first use.
        DB::table('ledger_accounts')->where('shop_id', $this->shop->id)->whereIn('code', [ChartOfAccounts::CUSTOMER_CREDIT, ChartOfAccounts::EXPIRED_CREDIT])->delete();

        $this->credit(3);
        $this->assertSame(-600000, $this->ledger(ChartOfAccounts::CUSTOMER_CREDIT));
        $this->assertHealthy();

        $this->sell(1, 0, credit: 2000);
        $this->assertSame(-400000, $this->ledger(ChartOfAccounts::CUSTOMER_CREDIT));

        StoreCredit::cashOut($this->customer, 1000, 'cash', null, $this->owner->id);
        $this->assertSame(-300000, $this->ledger(ChartOfAccounts::CUSTOMER_CREDIT));
        $this->assertHealthy();

        // Expired yesterday, so written off today, on or after the ledger's start.
        StoreCreditEntry::where('type', 'issue')->update(['expires_on' => CarbonImmutable::parse(BatchExpiry::today())->subDay()->toDateString()]);
        StoreCredit::expireDue();
        $this->assertSame(0, $this->ledger(ChartOfAccounts::CUSTOMER_CREDIT));
        $this->assertSame(-300000, $this->ledger(ChartOfAccounts::EXPIRED_CREDIT));
        $this->assertHealthy();
    }

    protected function ledger(string $code): int
    {
        app(LedgerPoster::class)->drain();

        return (int) DB::table('ledger_lines')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->where('ledger_lines.shop_id', $this->shop->id)
            ->where('ledger_accounts.code', $code)
            ->sum('amount_paise');
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
