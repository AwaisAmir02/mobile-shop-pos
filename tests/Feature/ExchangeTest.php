<?php

namespace Tests\Feature;

use App\Actions\CancelSale;
use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\Exchanges;
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
use App\Models\User;
use App\ReportQueries\LedgerHealthReport;
use App\Services\ProductGroupService;
use App\Support\MoneyFlow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * R3: exchange. Goods from an earlier sale come back and pay for a new sale,
 * saved together as a return and a sale; the difference is paid or refunded
 * as on any sale or return, and the two are undone together.
 */
class ExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected User $owner;

    protected Customer $customer;

    protected Product $small;

    protected Product $medium;

    protected Product $shawl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create(['name' => 'Libas', 'business_category' => 'clothing', 'variants_enabled' => true]);
        $this->owner = User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => true]);
        $this->customer = Customer::create(['shop_id' => $this->shop->id, 'name' => 'Ayesha', 'phone' => '03001234567']);
        $this->actingAs($this->owner);

        $groups = app(ProductGroupService::class);
        $group = $groups->createGroup($this->shop->id, 'Lawn Kurta', 'accessory', '');
        [$this->small, $this->medium] = $groups->addVariants($group, ProductGroupService::combineOptionPairs(['S', 'M'], []), 2000, 1000);
        $this->shawl = Product::create(['shop_id' => $this->shop->id, 'type' => 'accessory', 'name' => 'Shawl', 'price' => 3500, 'stock_quantity' => 0, 'details' => ['category' => null]]);

        foreach ([[$this->small, 1000, 2000], [$this->medium, 1000, 2000], [$this->shawl, 2000, 3500]] as [$product, $cost, $price]) {
            CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 10, 'cost_price' => $cost, 'selling_price' => $price]], null, 'paid', null, '2026-10-01', null, $this->owner->id);
        }
    }

    protected function sell(Product $product, int $quantity, float $paid, ?int $customerId = -1): Sale
    {
        return CreateSale::handle(
            lines: [['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null]],
            invoiceDiscount: 0.0, customerId: $customerId === -1 ? $this->customer->id : $customerId, userId: $this->owner->id,
            saleDate: now()->toDateString(), amountPaid: $paid, paymentMethod: 'cash',
        );
    }

    protected function line(Product $product, int $quantity = 1): array
    {
        return ['product_id' => $product->id, 'quantity' => $quantity, 'discount' => 0.0, 'custom_price' => null];
    }

    /** @return array{return: SaleReturn, sale: Sale} */
    protected function exchange(Sale $original, Product $back, array $newLines, float $paid = 0.0, ?int $customerId = -1, string $refundTo = 'auto', float $credit = 0.0): array
    {
        return Exchanges::handle(
            $original->fresh(),
            ['p'.$back->id => ['quantity' => '1']],
            $newLines,
            customerId: $customerId === -1 ? $original->customer_id : $customerId,
            userId: $this->owner->id,
            amountPaid: $paid,
            paymentMethod: 'cash',
            storeCredit: $credit,
            refundTo: $refundTo,
            refundMethod: 'cash',
        );
    }

    protected function money(string $source): float
    {
        return (float) (new MoneyFlow($this->shop->id))->movements()->where('source', $source)->sum('amount');
    }

    public function test_a_size_swap_moves_no_money_and_swaps_the_stock(): void
    {
        $original = $this->sell($this->small, 1, 2000);
        $before = $this->money('sale');

        ['return' => $return, 'sale' => $sale] = $this->exchange($original, $this->small, [$this->line($this->medium)]);

        $this->assertSame(10, $this->small->fresh()->stock_quantity);
        $this->assertSame(9, $this->medium->fresh()->stock_quantity);
        $this->assertSame(2000.0, (float) $return->refund_exchange);
        $this->assertSame(0.0, (float) $return->refund_cash);
        $this->assertSame($sale->id, $return->exchange_sale_id);
        $this->assertSame(0.0, $sale->amountDue());
        $this->assertSame([SalePaymentMethod::Exchange], $sale->payments->where('amount', '>', 0)->pluck('payment_method')->values()->all());
        $this->assertSame($before, $this->money('sale'), 'no money moved');
        $this->assertSame(0.0, $this->money('refund'));
        $this->assertStringStartsWith('Exchange: '.$return->number().' on '.$original->invoiceNumber(), $sale->payments->firstWhere('payment_method', SalePaymentMethod::Exchange)->methodLabel());
    }

    public function test_when_the_new_items_cost_more_the_customer_pays_the_difference(): void
    {
        $original = $this->sell($this->small, 1, 2000);
        $before = $this->money('sale');

        ['sale' => $sale] = $this->exchange($original, $this->small, [$this->line($this->shawl)], paid: 1500);

        $this->assertSame(0.0, $sale->amountDue());
        $this->assertSame(1500.0, $this->money('sale') - $before);
    }

    public function test_the_difference_can_be_paid_from_store_credit(): void
    {
        $earlier = $this->sell($this->medium, 1, 2000);
        SaleReturns::create($earlier, ['p'.$this->medium->id => ['quantity' => '1']], null, null, null, $this->owner->id, refundTo: SaleReturns::REFUND_CREDIT);
        $original = $this->sell($this->small, 1, 2000);

        ['sale' => $sale] = $this->exchange($original, $this->small, [$this->line($this->shawl)], credit: 1500);

        $this->assertSame(0.0, $sale->amountDue());
        $this->assertSame(500.0, StoreCredit::balance($this->customer->id));
    }

    public function test_when_the_new_items_cost_less_the_leftover_follows_the_refund_policy(): void
    {
        $original = $this->sell($this->shawl, 2, 7000);

        // Cash where the shop gives money back.
        ['return' => $cash] = $this->exchange($original, $this->shawl, [$this->line($this->small)]);
        $this->assertSame(2000.0, (float) $cash->refund_exchange);
        $this->assertSame(1500.0, (float) $cash->refund_cash);
        $this->assertSame(-1500.0, $this->money('refund'));

        // Store credit when chosen.
        ['return' => $credit] = $this->exchange($original, $this->shawl, [$this->line($this->medium)], refundTo: 'credit');
        $this->assertSame(1500.0, (float) $credit->refund_credit);
        $this->assertSame(1500.0, StoreCredit::balance($this->customer->id));
    }

    public function test_a_shop_without_money_refunds_puts_the_leftover_on_credit_and_a_walk_in_needs_a_customer(): void
    {
        $this->shop->update(['cash_refunds_allowed' => false]);
        $original = $this->sell($this->shawl, 1, 3500, null);
        $sales = Sale::count();

        $this->assertThrows(fn () => $this->exchange($original, $this->shawl, [$this->line($this->small)], customerId: null), ValidationException::class, 'Choose the customer');

        // Nothing at all was saved.
        $this->assertSame($sales, Sale::count());
        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(10, $this->small->fresh()->stock_quantity);
        $this->assertSame(9, $this->shawl->fresh()->stock_quantity);

        ['return' => $return, 'sale' => $sale] = $this->exchange($original, $this->shawl, [$this->line($this->small)], customerId: $this->customer->id);
        $this->assertSame(1500.0, (float) $return->refund_credit);
        $this->assertSame($this->customer->id, $sale->customer_id);
        $this->assertSame(1500.0, StoreCredit::balance($this->customer->id));
    }

    public function test_on_an_unpaid_sale_the_return_clears_what_was_owed_and_the_new_sale_is_paid_as_usual(): void
    {
        $original = $this->sell($this->small, 1, 0);

        ['return' => $return, 'sale' => $sale] = $this->exchange($original, $this->small, [$this->line($this->medium)]);

        $this->assertSame(2000.0, (float) $return->due_reduction);
        $this->assertSame(0.0, (float) $return->refund_exchange);
        $this->assertSame(0.0, $original->fresh()->amountDue());
        $this->assertSame(2000.0, $sale->amountDue(), 'nothing was due back, so the new sale is owed in full');
    }

    public function test_an_exchange_is_undone_as_a_whole_through_its_return(): void
    {
        $original = $this->sell($this->small, 1, 2000);
        ['return' => $return, 'sale' => $sale] = $this->exchange($original, $this->small, [$this->line($this->medium)]);

        $this->assertThrows(fn () => CancelSale::handle($sale->fresh(), $this->owner->id), ValidationException::class, 'Cancel that return');

        SaleReturns::cancel($return, $this->owner->id);

        $this->assertTrue($return->fresh()->isCancelled());
        $this->assertTrue(Sale::withoutGlobalScopes()->find($sale->id)->isCancelled());
        $this->assertSame(9, $this->small->fresh()->stock_quantity);
        $this->assertSame(10, $this->medium->fresh()->stock_quantity);
    }

    public function test_bad_exchanges_are_refused_and_save_nothing(): void
    {
        $original = $this->sell($this->small, 1, 2000);
        $sales = Sale::count();

        $this->assertThrows(fn () => $this->exchange($original, $this->small, []), ValidationException::class, 'Add what the customer is taking');
        $this->assertThrows(fn () => Exchanges::handle($original, ['p'.$this->small->id => ['quantity' => '2']], [$this->line($this->medium)], $this->customer->id, $this->owner->id), ValidationException::class);
        $this->assertThrows(fn () => $this->exchange($original, $this->small, [$this->line($this->medium)], paid: 2500), ValidationException::class);

        $this->assertSame($sales, Sale::count());
        $this->assertSame(10, $this->medium->fresh()->stock_quantity);
        $this->assertThrows(fn () => SalePaymentMethod::paymentAttributes('exchange', null, $this->shop->id, 10), ValidationException::class);
    }

    public function test_the_ledger_clears_the_exchange_to_nothing_and_stays_healthy_through_a_cancel(): void
    {
        if ($this->shop->fresh()->ledger_started_at === null) {
            StartLedger::handle(tap($this->shop)->update(['ledger_enabled' => true]));
        }

        DB::table('ledger_accounts')->where('shop_id', $this->shop->id)->where('code', ChartOfAccounts::EXCHANGE_CLEARING)->delete();

        $original = $this->sell($this->shawl, 1, 3500);
        ['return' => $return] = $this->exchange($original, $this->shawl, [$this->line($this->small)]);

        $this->assertSame(0, $this->ledger(ChartOfAccounts::EXCHANGE_CLEARING));
        $this->assertTrue(DB::table('ledger_accounts')->where('shop_id', $this->shop->id)->where('code', ChartOfAccounts::EXCHANGE_CLEARING)->exists());
        $this->assertHealthy();

        SaleReturns::cancel($return, $this->owner->id);
        $this->assertSame(0, $this->ledger(ChartOfAccounts::EXCHANGE_CLEARING));
        $this->assertHealthy();
    }

    // ── The screen ──────────────────────────────────────────────────────

    public function test_the_register_runs_an_exchange_opened_from_the_sale(): void
    {
        $original = $this->sell($this->small, 1, 2000);

        Livewire::test('sales.show', ['sale' => $original])->assertSeeHtml('data-exchange-items');

        Livewire::withQueryParams(['exchange' => $original->id])
            ->test('sales.create')
            ->assertSee('Exchange from '.$original->invoiceNumber())
            ->assertSet('customerId', (string) $this->customer->id)
            ->set('exchangeQuantities.p'.$this->small->id, '1')
            ->call('addToCart', $this->shawl->id)
            ->assertSeeHtml('data-exchange-line')
            ->assertDontSeeHtml('data-add-trade-in')
            ->call('completeSale')
            ->assertHasNoErrors();

        $return = SaleReturn::sole();
        $sale = Sale::findOrFail($return->exchange_sale_id);
        $this->assertSame(3500.0, (float) $sale->total);
        $this->assertSame(2000.0, (float) $return->refund_exchange);
        $this->assertSame(0.0, $sale->amountDue(), 'the rest was paid in cash');

        Livewire::test('sales.show', ['sale' => $original])->assertSee($return->number())->assertSee($sale->invoiceNumber());
    }

    public function test_the_register_shows_the_leftover_and_reports_return_errors(): void
    {
        $original = $this->sell($this->shawl, 1, 3500);

        Livewire::withQueryParams(['exchange' => $original->id])
            ->test('sales.create')
            ->set('exchangeQuantities.p'.$this->shawl->id, '1')
            ->call('addToCart', $this->small->id)
            ->assertSeeHtml('data-exchange-leftover')
            ->assertSee('Rs 1,500.00 back to the customer')
            ->set('exchangeQuantities.p'.$this->shawl->id, '3')
            ->call('completeSale')
            ->assertHasErrors('exchange');

        $this->assertSame(0, SaleReturn::count());
    }

    public function test_staff_without_the_returns_permission_get_an_ordinary_sale(): void
    {
        $original = $this->sell($this->small, 1, 2000);
        $role = Role::create(['shop_id' => $this->shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->shop->id, 'is_owner' => false, 'role_id' => $role->id]));

        Livewire::withQueryParams(['exchange' => $original->id])
            ->test('sales.create')
            ->assertSet('exchangeSaleId', null)
            ->assertDontSee('Exchange from');
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
