<?php

namespace Tests\Feature;

use App\Actions\CancelExpense;
use App\Actions\CancelSale;
use App\Actions\CreateStockIn;
use App\Actions\CreateUdhaarTransaction;
use App\Actions\RecordSalePayment;
use App\Actions\RecordStockInPayment;
use App\Actions\StartLedger;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\LedgerBaseline;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\LedgerOutbox;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\LedgerHealthReport;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerSetupTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Shop $theShop;
    protected Vendor $vendor;
    protected Customer $customer;
    protected Product $tea;

    /** A shop with real history before the ledger: stock, dues, payments, an overpayment. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = $this->shop(['ledger_enabled' => true]);
        $this->vendor = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Acme']);
        $this->customer = Customer::create(['shop_id' => $this->theShop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
        $this->tea = $this->piece($this->theShop, ['name' => 'Tea', 'cost_price' => null]);

        $batch = CreateStockIn::handle([['product_id' => $this->tea->id, 'quantity' => 20, 'cost_price' => 100.0, 'selling_price' => 150.0]], $this->vendor->id, 'unpaid', null, now()->subMonth()->toDateString(), null, auth()->id())->first();
        RecordStockInPayment::add($batch, 500, auth()->id());
    }

    protected function drain(): void
    {
        app(LedgerPoster::class)->drain();
    }

    protected function balance(string $code): float
    {
        return (float) LedgerLine::query()->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')->where('ledger_accounts.code', $code)->sum('amount_paise') / 100;
    }

    protected function assertHealthy(): void
    {
        $this->drain();

        foreach ((new LedgerHealthReport($this->theShop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'], abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }

    protected function history(): void
    {
        $sale = \App\Actions\CreateSale::handle(lines: [['product_id' => $this->tea->id, 'quantity' => 4, 'discount' => 0.0, 'custom_price' => null]], invoiceDiscount: 0.0, customerId: $this->customer->id, userId: auth()->id(), saleDate: now()->subWeek()->toDateString(), amountPaid: 100);
        CreateUdhaarTransaction::handle($this->customer, 'given', '300', now()->subWeek()->toDateString());
        PartyLedgerEntry::create(['shop_id' => $this->theShop->id, 'vendor_id' => $this->vendor->id, 'kind' => 'opening', 'direction' => 'credit', 'amount' => 250, 'entry_date' => now()->subMonth()->toDateString(), 'note' => 'Old balance']);
        Expense::create(['shop_id' => $this->theShop->id, 'description' => 'Old rent', 'amount' => 5000, 'expense_date' => now()->subWeek()->toDateString()]);
    }

    // ── The opening entry ──────────────────────────────────────────────

    public function test_the_opening_entry_carries_what_the_records_say_and_balances(): void
    {
        $this->history();

        StartLedger::handle($this->theShop, ['cash' => 2000]);

        $this->assertSame(1, LedgerEntry::count());
        $entry = LedgerEntry::sole();
        $this->assertSame('opening', $entry->source_type);
        $this->assertSame(now()->toDateString(), $entry->entry_date->toDateString());

        // 16 units left at 100.
        $this->assertSame(1600.0, $this->balance(Chart::INVENTORY));
        // Sale 600 less 100 paid, plus 300 lent.
        $this->assertSame(800.0, $this->balance(Chart::RECEIVABLE));
        // 2000 less 500 paid, plus 250 opening.
        $this->assertSame(-1750.0, $this->balance(Chart::PAYABLE));
        $this->assertSame(2000.0, $this->balance(Chart::CASH));
        $this->assertSame(-2650.0, $this->balance(Chart::OPENING));
        $this->assertSame(0, (int) LedgerLine::sum('amount_paise'));

        $this->assertHealthy();
    }

    public function test_past_income_costs_and_cash_movements_are_history_not_opening_balances(): void
    {
        $this->history();

        StartLedger::handle($this->theShop);

        foreach ([Chart::SALES, Chart::COGS, Chart::EXPENSES, Chart::NOT_CLASSIFIED] as $code) {
            $this->assertSame(0.0, $this->balance($code), $code);
        }
    }

    public function test_opening_agrees_customer_by_customer_with_the_statement(): void
    {
        $this->history();

        StartLedger::handle($this->theShop);

        $owed = LedgerLine::where('party_type', 'customer')->where('party_id', $this->customer->id)->sum('amount_paise') / 100;
        $this->assertEqualsWithDelta(CustomerDuesStatement::for($this->customer->fresh())->total(), $owed, 0.001);
    }

    public function test_stock_no_batch_covers_opens_at_the_products_current_cost(): void
    {
        Product::create(['shop_id' => $this->theShop->id, 'type' => 'grocery', 'name' => 'Loose', 'price' => 100, 'cost_price' => 40, 'stock_quantity' => 10, 'details' => ['category' => null]]);

        StartLedger::handle($this->theShop);

        $this->assertSame(2000.0 + 400, $this->balance(Chart::INVENTORY));
        $this->assertHealthy();
    }

    public function test_bank_balances_open_on_their_own_accounts(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);

        StartLedger::handle($this->theShop, ['cash' => 100, 'accounts' => [$account->id => 900]]);

        $this->assertSame(900.0, $this->balance(Chart::bankCode($account->id)));
    }

    public function test_an_overpaid_record_opens_as_it_really_is_and_health_explains_it(): void
    {
        $sale = \App\Actions\CreateSale::handle(lines: [['product_id' => $this->tea->id, 'quantity' => 1, 'discount' => 0.0, 'custom_price' => null]], invoiceDiscount: 0.0, customerId: $this->customer->id, userId: auth()->id(), saleDate: now()->subWeek()->toDateString(), amountPaid: 150);
        RecordSalePayment::handle($sale, 50, auth()->id());

        StartLedger::handle($this->theShop);

        $this->assertSame(-50.0, $this->balance(Chart::RECEIVABLE));
        $this->assertHealthy();
    }

    public function test_a_shop_with_nothing_opens_with_nothing(): void
    {
        $empty = $this->shop(['name' => 'Empty', 'ledger_enabled' => true]);

        StartLedger::handle($empty);

        $this->assertSame(0, LedgerEntry::where('shop_id', $empty->id)->count());
        $this->assertNotNull($empty->fresh()->ledger_ready_at);
    }

    // ── After the start ────────────────────────────────────────────────

    public function test_paying_an_old_due_after_the_start_only_moves_that_money(): void
    {
        $this->history();
        $sale = \App\Models\Sale::sole();
        StartLedger::handle($this->theShop);
        $receivable = $this->balance(Chart::RECEIVABLE);

        RecordSalePayment::handle($sale, 200, auth()->id());
        $this->drain();

        $this->assertSame($receivable - 200, $this->balance(Chart::RECEIVABLE));
        $this->assertSame(200.0, $this->balance(Chart::NOT_CLASSIFIED));
        $this->assertSame(0.0, $this->balance(Chart::SALES));
        $this->assertHealthy();
    }

    public function test_cancelling_an_old_sale_goes_to_prior_period_and_this_months_figures_stay_clean(): void
    {
        $this->history();
        $sale = \App\Models\Sale::sole();
        StartLedger::handle($this->theShop);

        CancelSale::handle($sale, auth()->id());
        $this->drain();

        $this->assertSame(0.0, $this->balance(Chart::SALES));
        $this->assertSame(0.0, $this->balance(Chart::COGS));
        $this->assertNotSame(0.0, $this->balance(Chart::PRIOR_PERIOD));
        // Stock came back, and what the customer owes dropped.
        $this->assertSame(2000.0, $this->balance(Chart::INVENTORY));
        $this->assertHealthy();
    }

    public function test_a_sale_earlier_on_the_start_day_is_not_expected_in_the_ledger(): void
    {
        $this->sell($this->tea, 5);

        StartLedger::handle($this->theShop);

        $this->assertSame(0.0, $this->balance(Chart::SALES));
        $this->assertHealthy();

        $this->sell($this->tea, 2);
        $this->assertHealthy();
        $this->assertSame(-300.0, $this->balance(Chart::SALES));
    }

    public function test_paying_an_old_vendor_due_reconciles(): void
    {
        $batch = \App\Models\StockIn::sole();
        StartLedger::handle($this->theShop);

        RecordStockInPayment::add($batch, 700, auth()->id());

        $this->assertHealthy();
        $this->assertSame(-800.0, $this->balance(Chart::PAYABLE));
    }

    public function test_a_sale_of_old_stock_after_the_start_costs_inventory_normally(): void
    {
        StartLedger::handle($this->theShop);

        $this->sell($this->tea, 2);
        $this->drain();

        $this->assertSame(200.0, $this->balance(Chart::COGS));
        $this->assertSame(1800.0, $this->balance(Chart::INVENTORY));
        $this->assertHealthy();
    }

    public function test_cancelling_an_old_expense_does_not_touch_this_months_expenses(): void
    {
        $this->history();
        $expense = Expense::sole();
        StartLedger::handle($this->theShop);

        CancelExpense::handle($expense, auth()->id());
        $this->drain();

        $this->assertSame(0.0, $this->balance(Chart::EXPENSES));
        $this->assertSame(-5000.0, $this->balance(Chart::PRIOR_PERIOD));
    }

    // ── Setup in steps ─────────────────────────────────────────────────

    public function test_nothing_posts_until_setup_has_finished_and_it_can_simply_run_again(): void
    {
        // As if setup stopped after noting changes began, before the opening entry.
        $this->theShop->forceFill(['ledger_started_at' => now(), 'ledger_epoch' => 1])->save();
        \App\Ledger\ChartOfAccounts::ensureFor($this->theShop);
        LedgerBaseline::create(['shop_id' => $this->theShop->id, 'epoch' => 1, 'source_type' => 'expense', 'source_id' => 999, 'lines' => []]);

        $expense = Expense::create(['shop_id' => $this->theShop->id, 'description' => 'During', 'amount' => 70, 'expense_date' => now()->toDateString()]);
        $this->assertSame(1, LedgerOutbox::count(), 'Changes are noted from the first step.');

        $this->drain();
        $this->assertSame(0, LedgerEntry::count(), 'But nothing posts yet.');

        Livewire::test('ledger.setup')->assertSee("didn't finish", false);

        $resumed = StartLedger::handle($this->theShop->fresh());

        $this->assertSame(1, $resumed->ledger_epoch, 'Same epoch, not a new one.');
        $this->assertNotNull($resumed->ledger_ready_at);
        $this->assertFalse(LedgerBaseline::where('source_id', 999)->exists());

        // It was captured in the baseline, so it doesn't post on top.
        $this->drain();
        $this->assertSame(0.0, $this->balance(Chart::EXPENSES));

        $expense->update(['amount' => 100]);
        $this->drain();
        $this->assertSame(0, LedgerOutbox::count());
    }

    public function test_starting_twice_is_refused_and_setup_redirects_once_ready(): void
    {
        StartLedger::handle($this->theShop);

        $this->assertRefused(fn () => StartLedger::handle($this->theShop->fresh()), 'already started');
        auth()->user()->unsetRelation('shop');
        Livewire::test('ledger.setup')->assertRedirect(route('ledger.trial-balance'));
    }

    public function test_restarting_after_a_freeze_opens_a_new_epoch_from_todays_figures(): void
    {
        StartLedger::handle($this->theShop, ['cash' => 100]);
        $this->theShop->fresh()->update(['ledger_enabled' => false]);
        $this->sell($this->tea, 5);
        $this->theShop->fresh()->update(['ledger_enabled' => true]);

        auth()->user()->unsetRelation('shop');
        Livewire::test('ledger.trial-balance')->assertSee('Start a New Ledger');
        Livewire::test('ledger.setup')->assertSee('starts a new ledger')->set('cash', '500')->call('start')->assertHasNoErrors();

        $shop = $this->theShop->fresh();
        $this->assertSame(2, $shop->ledger_epoch);
        $this->assertNull($shop->ledger_frozen_at);

        $inventory = LedgerLine::query()->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')->where('ledger_entries.epoch', 2)->where('ledger_accounts.code', Chart::INVENTORY)->sum('amount_paise');
        $this->assertSame(150000, (int) $inventory, '15 left at 100.');
        $this->assertHealthy();
    }

    // ── The screen ─────────────────────────────────────────────────────

    public function test_the_wizard_shows_the_balances_and_the_difference_as_you_type(): void
    {
        $this->history();
        ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);

        Livewire::test('ledger.setup')
            ->assertSee('Rs 1,600.00')
            ->assertSee('Accounts Receivable')
            ->assertSee('Rs 800.00 Dr')
            ->assertSee('Accounts Payable')
            ->assertSee('Rs 1,750.00 Cr')
            ->assertSee('Meezan')
            ->assertSee('Rs 650.00 Cr')
            ->set('cash', '2000')
            ->assertSee('Rs 2,650.00 Cr');
    }

    public function test_the_wizard_starts_it_with_what_was_typed(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);

        Livewire::test('ledger.setup')
            ->set('cash', '1234.50')
            ->set("accountBalances.{$account->id}", '4000')
            ->call('start')
            ->assertHasNoErrors()
            ->assertRedirect(route('ledger.trial-balance'));

        $this->assertSame(1234.5, $this->balance(Chart::CASH));
        $this->assertSame(4000.0, $this->balance(Chart::bankCode($account->id)));
    }

    public function test_it_refuses_a_negative_balance_and_warns_about_stock_with_no_cost(): void
    {
        Product::create(['shop_id' => $this->theShop->id, 'type' => 'grocery', 'name' => 'NoCost', 'price' => 100, 'cost_price' => null, 'stock_quantity' => 3, 'details' => ['category' => null]]);

        Livewire::test('ledger.setup')
            ->assertSee('have no cost')
            ->set('cash', '-5')
            ->call('start')
            ->assertHasErrors('cash');

        $this->assertNull($this->theShop->fresh()->ledger_started_at);
    }

    public function test_a_forged_account_from_another_shop_is_ignored(): void
    {
        $other = $this->shop(['name' => 'Other']);
        $theirs = ShopAccount::create(['shop_id' => $other->id, 'name' => 'Theirs', 'provider_type' => 'bank']);
        $this->actingAs(User::where('shop_id', $this->theShop->id)->first());

        Livewire::test('ledger.setup')->set("accountBalances.{$theirs->id}", '5000')->call('start')->assertHasNoErrors();

        $this->assertFalse(\App\Models\LedgerAccount::where('shop_id', $this->theShop->id)->where('code', Chart::bankCode($theirs->id))->exists());
    }

    public function test_only_the_owner_can_set_it_up_and_only_when_switched_on(): void
    {
        $this->get(route('ledger.setup'))->assertOk();

        $role = Role::create(['shop_id' => $this->theShop->id, 'name' => 'M', 'permissions' => ['sales']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $this->get(route('ledger.setup'))->assertForbidden();

        $off = $this->shop(['name' => 'Off']);
        $this->get(route('ledger.setup'))->assertForbidden();
        $this->assertFalse($off->fresh()->ledger_enabled);
    }
}
