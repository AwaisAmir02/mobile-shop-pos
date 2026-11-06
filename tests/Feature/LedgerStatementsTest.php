<?php

namespace Tests\Feature;

use App\Actions\CreateSale;
use App\Actions\CreateStockIn;
use App\Actions\CreateStockWriteOff;
use App\Actions\OwnerMoney;
use App\Actions\Payroll;
use App\Actions\StartLedger;
use App\Enums\WriteOffReason;
use App\Ledger\LedgerPoster;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LedgerOutbox;
use App\Models\Payslip;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\LedgerHealthReport;
use App\ReportQueries\LedgerStatements;
use App\ReportQueries\ProfitReport;
use App\Services\ShopReportService;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerStatementsTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Shop $theShop;
    protected Product $tea;
    protected Vendor $vendor;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = StartLedger::handle($this->shop(['ledger_enabled' => true, 'payroll_enabled' => true]), ['cash' => 10000]);
        $this->tea = $this->piece($this->theShop, ['name' => 'Tea', 'cost_price' => null]);
        $this->vendor = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Acme']);
        $this->customer = Customer::create(['shop_id' => $this->theShop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
    }

    /** Stock 2,000, sale of 4 for 600 at cost 400, write-off 100, rent 50, salary 30. */
    protected function busyMonth(): void
    {
        CreateStockIn::handle([['product_id' => $this->tea->id, 'quantity' => 20, 'cost_price' => 100.0, 'selling_price' => 150.0]], $this->vendor->id, 'unpaid', null, now()->toDateString(), null, auth()->id());
        CreateSale::handle(lines: [['product_id' => $this->tea->id, 'quantity' => 4, 'discount' => 0.0, 'custom_price' => null]], invoiceDiscount: 0.0, customerId: $this->customer->id, userId: auth()->id(), saleDate: now()->toDateString(), amountPaid: 500, paymentMethod: 'cash');
        CreateStockWriteOff::handle($this->tea, 1, WriteOffReason::Damaged, null, auth()->id(), now()->toDateString());
        Expense::create(['shop_id' => $this->theShop->id, 'description' => 'Rent', 'amount' => 50, 'expense_date' => now()->toDateString(), 'payment_method' => 'cash']);

        Employee::create(['shop_id' => $this->theShop->id, 'name' => 'Ali', 'rate' => 30]);
        Payroll::generate($this->theShop->id, now()->format('Y-m'), null, auth()->id());
        Payroll::pay(Payslip::sole(), now()->toDateString(), null, auth()->id());

        app(LedgerPoster::class)->drain();
    }

    protected function statements(): LedgerStatements
    {
        return new LedgerStatements($this->theShop->fresh());
    }

    // ── Profit & Loss ──────────────────────────────────────────────────

    public function test_profit_and_loss_works_out_gross_and_net(): void
    {
        $this->busyMonth();

        $pl = $this->statements()->profitAndLoss(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(600.0, $pl['total_income']);
        $this->assertSame(400.0, (float) $pl['cogs']);
        $this->assertSame(200.0, $pl['gross_profit']);
        // Write-off 100, salary 30, rent 50.
        $this->assertSame(180.0, $pl['total_expenses']);
        $this->assertSame(20.0, $pl['net_profit']);
    }

    public function test_gross_profit_agrees_with_the_gross_profit_report(): void
    {
        $this->busyMonth();

        $report = (new ProfitReport(now()->startOfMonth()->toDateString(), now()->toDateString()))->totals();
        $pl = $this->statements()->profitAndLoss(now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertEqualsWithDelta($report['cogs'], $pl['cogs'], 0.001);
        $this->assertEqualsWithDelta($report['grossProfit'], $pl['gross_profit'], 0.001);
    }

    public function test_it_says_it_will_not_match_net_and_leaves_net_alone(): void
    {
        $this->busyMonth();

        // Net is Revenue − Expenses, without the cost of what's sold.
        $net = app(ShopReportService::class)->summary($this->theShop, now()->startOfMonth(), now()->endOfMonth())['netSummary'];
        $this->assertEqualsWithDelta(600 - 50 - 30, $net, 0.001);

        Livewire::test('ledger.profit-and-loss')->assertSee("This won't match Net", false)->assertSee('Rs 20.00');
    }

    public function test_dates_narrow_it(): void
    {
        $this->busyMonth();

        $tomorrow = now()->addDay()->toDateString();

        Livewire::test('ledger.profit-and-loss')->set('from', $tomorrow)->set('to', $tomorrow)->assertDontSee('Rs 600.00');
    }

    // ── Balance Sheet ──────────────────────────────────────────────────

    public function test_the_balance_sheet_balances_and_profit_lands_in_equity(): void
    {
        $this->busyMonth();

        $sheet = $this->statements()->balanceSheet(now()->toDateString());

        $this->assertTrue($sheet['balanced']);
        $this->assertSame($sheet['total_assets'], round($sheet['total_liabilities'] + $sheet['total_equity'], 2));

        $profit = collect($sheet['equity'])->firstWhere('name', 'Profit Since the Start');
        $this->assertSame(20.0, (float) $profit['amount']);

        // 10,000 opening − 50 rent − 30 salary + 500 received.
        $this->assertSame(10420.0, (float) collect($sheet['assets'])->firstWhere('name', 'Cash in Hand')['amount']);
        $this->assertSame(2000.0, (float) collect($sheet['liabilities'])->firstWhere('name', 'Accounts Payable')['amount']);
    }

    public function test_drawings_reduce_equity_not_profit(): void
    {
        $this->busyMonth();
        OwnerMoney::withdraw($this->theShop, 200, now()->toDateString(), 'cash', null, null, auth()->id());
        app(LedgerPoster::class)->drain();

        $sheet = $this->statements()->balanceSheet(now()->toDateString());

        $this->assertTrue($sheet['balanced']);
        $this->assertSame(-200.0, (float) collect($sheet['equity'])->firstWhere('name', "Owner's Drawings")['amount']);
        $this->assertSame(20.0, (float) collect($sheet['equity'])->firstWhere('name', 'Profit Since the Start')['amount']);
    }

    public function test_it_is_honest_about_money_with_no_recorded_account(): void
    {
        $this->busyMonth();
        Expense::create(['shop_id' => $this->theShop->id, 'description' => 'Unknown', 'amount' => 75, 'expense_date' => now()->toDateString()]);

        Livewire::test('ledger.balance-sheet')->assertSee('Rs 75.00 of money moved with no record');
    }

    // ── Cash Book ──────────────────────────────────────────────────────

    public function test_the_cash_book_runs_a_balance_from_opening_to_closing(): void
    {
        $this->busyMonth();

        $book = $this->statements()->cashBook('', now()->startOfMonth()->toDateString(), now()->toDateString());

        $this->assertSame(10500.0, (float) $book['total_in']);
        $this->assertSame(80.0, (float) $book['total_out']);
        $this->assertSame(10420.0, (float) $book['closing']);
        $this->assertSame(10420.0, (float) end($book['rows'])['balance']);
    }

    public function test_opening_comes_from_before_the_period_and_accounts_can_be_filtered(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);
        Expense::create(['shop_id' => $this->theShop->id, 'description' => 'From bank', 'amount' => 40, 'expense_date' => now()->toDateString(), 'payment_method' => 'account', 'shop_account_id' => $account->id]);
        Expense::create(['shop_id' => $this->theShop->id, 'description' => 'From cash', 'amount' => 60, 'expense_date' => now()->addDay()->toDateString(), 'payment_method' => 'cash']);
        app(LedgerPoster::class)->drain();

        $later = $this->statements()->cashBook('', now()->addDay()->toDateString(), null);
        $this->assertSame(10000.0 - 40, (float) $later['opening']);

        $bank = $this->statements()->cashBook('1100-'.$account->id, null, null);
        $this->assertCount(1, $bank['rows']);
        $this->assertSame('From bank', str_replace('Expense: ', '', $bank['rows'][0]['description']));

        Livewire::test('ledger.cash-book')->set('from', '')->set('to', '')->assertSee('Meezan')->assertSee('Expense: From bank');
    }

    // ── Branch profit ──────────────────────────────────────────────────

    public function test_branch_profit_splits_by_branch_and_keeps_costs_with_no_branch_apart(): void
    {
        $second = Branch::create(['shop_id' => $this->theShop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $main = BranchContext::current()->mainBranchIdFor($this->theShop->id);

        CreateStockIn::handle([['product_id' => $this->tea->id, 'quantity' => 20, 'cost_price' => 100.0, 'selling_price' => 150.0]], $this->vendor->id, 'unpaid', null, now()->toDateString(), null, auth()->id());

        $sale = CreateSale::handle(lines: [['product_id' => $this->tea->id, 'quantity' => 2, 'discount' => 0.0, 'custom_price' => null]], invoiceDiscount: 0.0, customerId: $this->customer->id, userId: auth()->id(), saleDate: now()->toDateString(), amountPaid: 0);
        $sale->branch_id = $second->id;
        $sale->save();

        $expense = new Expense(['description' => 'Rent', 'amount' => 70, 'expense_date' => now()->toDateString()]);
        $expense->shop_id = $this->theShop->id;
        $expense->branch_id = $main;
        $expense->save();

        // A stock correction has no branch.
        Product::create(['shop_id' => $this->theShop->id, 'type' => 'grocery', 'name' => 'Loose', 'price' => 10, 'cost_price' => 5, 'stock_quantity' => 2, 'details' => ['category' => null]]);

        app(LedgerPoster::class)->drain();

        $report = $this->statements()->branchProfitAndLoss(null, null);

        $this->assertSame(['Main', 'Second', 'Not by Branch', 'Total'], $report['columns']);

        $net = collect($report['rows'])->firstWhere('name', 'Net Profit')['amounts'];
        $this->assertSame(-70.0, (float) $net[0], 'Main: just the rent.');
        $this->assertSame(100.0, (float) $net[1], 'Second: 300 sold less 200 cost.');
        $this->assertSame(10.0, (float) $net[2], 'No branch: the stock correction.');
        $this->assertEqualsWithDelta(array_sum(array_slice($net, 0, 3)), $net[3], 0.001, 'Columns add up to the total.');

        Livewire::test('ledger.branch-profit')->set('from', '')->set('to', '')->assertSee('Second')->assertSee('Not by Branch');
        Livewire::test('ledger.trial-balance')->assertSee('Branch Profit');
    }

    public function test_the_branch_link_only_shows_with_more_than_one_branch(): void
    {
        Livewire::test('ledger.trial-balance')->assertDontSee('Branch Profit');
    }

    // ── Exports ────────────────────────────────────────────────────────

    public function test_every_report_exports_rows_matching_its_headers(): void
    {
        $this->busyMonth();

        foreach (['ledger.trial-balance', 'ledger.profit-and-loss', 'ledger.balance-sheet', 'ledger.cash-book', 'ledger.branch-profit'] as $screen) {
            Livewire::test($screen)->call('exportPdf')->assertFileDownloaded();
            Livewire::test($screen)->call('exportExcel')->assertFileDownloaded();
        }
    }

    // ── Access ─────────────────────────────────────────────────────────

    public function test_reports_need_the_permission_which_is_off_by_default_and_the_owner_always_has_it(): void
    {
        $routes = ['ledger.trial-balance', 'ledger.profit-and-loss', 'ledger.balance-sheet', 'ledger.cash-book', 'ledger.branch-profit', 'ledger.health'];

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }

        $none = Role::create(['shop_id' => $this->theShop->id, 'name' => 'None', 'permissions' => ['sales', 'expenses', 'reports']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $none->id]));

        foreach ($routes as $route) {
            $this->get(route($route))->assertForbidden();
        }

        $viewer = Role::create(['shop_id' => $this->theShop->id, 'name' => 'Viewer', 'permissions' => ['ledger-reports', 'dashboard']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $viewer->id]));

        foreach ($routes as $route) {
            $this->get(route($route))->assertOk();
        }

        $this->get(route('dashboard'))->assertSee(route('ledger.trial-balance'));
    }

    public function test_setup_and_owner_money_stay_owner_only_even_with_the_permission(): void
    {
        $viewer = Role::create(['shop_id' => $this->theShop->id, 'name' => 'Viewer', 'permissions' => ['ledger-reports']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $viewer->id]));

        $this->get(route('ledger.setup'))->assertForbidden();
        $this->get(route('ledger.owner-money'))->assertForbidden();
        Livewire::test('ledger.trial-balance')->assertDontSee('Owner & Held Money');
    }

    public function test_reports_are_off_when_the_ledger_is_and_empty_before_it_starts(): void
    {
        $off = $this->shop(['name' => 'Off']);
        $this->get(route('ledger.profit-and-loss'))->assertForbidden();

        $off->update(['ledger_enabled' => true]);
        auth()->user()->unsetRelation('shop');

        foreach (['ledger.profit-and-loss', 'ledger.balance-sheet', 'ledger.cash-book', 'ledger.branch-profit'] as $screen) {
            Livewire::test($screen)->assertSee("The ledger hasn't started");
        }
    }

    public function test_a_frozen_ledger_still_shows_its_reports(): void
    {
        $this->busyMonth();
        $this->theShop->fresh()->update(['ledger_enabled' => false]);
        $this->theShop->fresh()->update(['ledger_enabled' => true]);
        auth()->user()->unsetRelation('shop');

        Livewire::test('ledger.profit-and-loss')->assertSee('Rs 20.00');
    }

    public function test_another_shops_figures_never_appear(): void
    {
        $this->busyMonth();

        $this->shop(['name' => 'Other', 'ledger_enabled' => true]);
        StartLedger::handle(auth()->user()->shop);
        auth()->user()->unsetRelation('shop');

        Livewire::test('ledger.profit-and-loss')->assertDontSee('Rs 600.00');
        Livewire::test('ledger.balance-sheet')->assertDontSee('Accounts Payable');
    }

    public function test_reports_warn_when_something_failed_to_post(): void
    {
        LedgerOutbox::mark($this->theShop->id, 'expense', 999);
        LedgerOutbox::query()->update(['attempts' => LedgerPoster::MAX_ATTEMPTS]);

        Livewire::test('ledger.profit-and-loss')->assertSee("couldn't post to the ledger", false);
    }

    // ── Health still reconciles, with the cost check added ─────────────

    public function test_health_reconciles_cost_of_goods_too(): void
    {
        $this->busyMonth();

        $checks = collect((new LedgerHealthReport($this->theShop->fresh()))->checks());

        $this->assertSame(400.0, $checks->firstWhere('label', 'Cost of goods sold since the start')['ledger']);

        foreach ($checks as $check) {
            $this->assertLessThanOrEqual($check['tolerance'], abs($check['unexplained']), $check['label']);
        }
    }
}
