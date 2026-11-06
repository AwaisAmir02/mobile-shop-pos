<?php

namespace Tests\Feature;

use App\Actions\CancelBalanceLoad;
use App\Actions\Payroll;
use App\Actions\RecordModulePayment;
use App\Actions\StartLedger;
use App\Enums\AdvanceType;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\BalanceLoad;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\NadraVerification;
use App\Models\Payslip;
use App\Models\Repair;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\SimSale;
use App\Models\WalletLoad;
use App\ReportQueries\LedgerHealthReport;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerPayrollAndServicesTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Shop $theShop;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = StartLedger::handle($this->shop(['ledger_enabled' => true, 'payroll_enabled' => true]));
        $this->customer = Customer::create(['shop_id' => $this->theShop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
    }

    /** @return array<string, float> */
    protected function balances(): array
    {
        app(LedgerPoster::class)->drain();

        return LedgerLine::query()
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')
            ->groupBy('ledger_accounts.code')
            ->selectRaw('ledger_accounts.code, SUM(amount_paise) as paise')
            ->toBase()
            ->pluck('paise', 'code')
            ->map(fn ($paise) => (float) $paise / 100)
            ->filter()
            ->all();
    }

    protected function assertBalances(array $expected): void
    {
        $actual = $this->balances();
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);

        $this->assertEquals($expected, $actual);
        $this->assertSame(0, (int) LedgerLine::sum('amount_paise'));
    }

    protected function assertHealthy(): void
    {
        app(LedgerPoster::class)->drain();

        foreach ((new LedgerHealthReport($this->theShop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'], abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }
    }

    protected function module(array $attributes): array
    {
        return $attributes + ['shop_id' => $this->theShop->id, 'user_id' => auth()->id(), 'customer_id' => $this->customer->id, 'payment_status' => 'unpaid', 'amount_paid' => 0];
    }

    // ── Pass-through: only what the shop keeps is income ───────────────

    public function test_a_balance_load_keeps_only_the_fee_as_income(): void
    {
        BalanceLoad::create($this->module(['network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'fee' => 20, 'discount' => 5, 'total' => 515]));

        $this->assertBalances([Chart::RECEIVABLE => 515, Chart::CLEARING => -500, Chart::SERVICE_INCOME => -15]);
        $this->assertHealthy();
    }

    public function test_a_bill_payment_is_pass_through(): void
    {
        $category = BillCategory::create(['shop_id' => $this->theShop->id, 'name' => 'Power']);
        $provider = BillProvider::create(['shop_id' => $this->theShop->id, 'bill_category_id' => $category->id, 'name' => 'K-Electric']);
        BillPayment::create($this->module(['bill_category_id' => $category->id, 'bill_provider_id' => $provider->id, 'consumer_number' => 'x', 'consumer_name' => 'y', 'amount' => 1000, 'fee' => 50, 'discount' => 0, 'total' => 1050, 'payment_status' => 'paid', 'amount_paid' => 1050]));

        $this->assertBalances([Chart::NOT_CLASSIFIED => 1050, Chart::CLEARING => -1000, Chart::SERVICE_INCOME => -50]);
        $this->assertHealthy();
    }

    public function test_wallet_cash_in_keeps_the_fee_whether_or_not_it_was_inside_the_amount(): void
    {
        WalletLoad::create($this->module(['direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 1000, 'fee' => 20, 'discount' => 0, 'net_amount' => 1000, 'total' => 1020]));
        $this->assertBalances([Chart::RECEIVABLE => 1020, Chart::CLEARING => -1000, Chart::SERVICE_INCOME => -20]);

        // Fee taken from inside the amount: 980 goes out, 20 is kept, 1000 is collected.
        WalletLoad::create($this->module(['direction' => 'cash_in', 'provider' => 'JazzCash', 'amount' => 1000, 'fee' => 20, 'discount' => 0, 'fee_included_in_amount' => true, 'net_amount' => 980, 'total' => 1000]));
        $this->assertBalances([Chart::RECEIVABLE => 2020, Chart::CLEARING => -1980, Chart::SERVICE_INCOME => -40]);
        $this->assertHealthy();
    }

    public function test_wallet_cash_out_pays_cash_from_the_till_and_nobody_owes_anything(): void
    {
        WalletLoad::create($this->module(['direction' => 'cash_out', 'provider' => 'JazzCash', 'amount' => 5000, 'fee' => 50, 'discount' => 0, 'net_amount' => 5000, 'total' => 5050]));

        $this->assertBalances([Chart::CLEARING => 5050, Chart::CASH => -5000, Chart::SERVICE_INCOME => -50]);
        $this->assertArrayNotHasKey(Chart::RECEIVABLE, $this->balances());
        $this->assertHealthy();
    }

    // ── The shop's own services ────────────────────────────────────────

    public function test_sims_nadra_and_services_are_income_and_repairs_are_repair_income(): void
    {
        SimSale::create($this->module(['network' => 'Jazz', 'sim_type' => 'prepaid', 'sim_form' => 'physical', 'sim_number' => '03001234567', 'amount' => 200, 'fee' => 0, 'discount' => 0, 'total' => 200]));
        NadraVerification::create($this->module(['phone_number' => '03001112223', 'cnic_number' => '42101-1234567-1', 'amount' => 300, 'fee' => 50, 'discount' => 0, 'total' => 350]));
        $category = ServiceCategory::create(['shop_id' => $this->theShop->id, 'name' => 'Installs']);
        $item = ServiceItem::create(['shop_id' => $this->theShop->id, 'service_category_id' => $category->id, 'name' => 'Guard']);
        ServicePayment::create($this->module(['service_category_id' => $category->id, 'service_item_id' => $item->id, 'amount' => 1000]));
        Repair::create($this->module(['category' => 'Screen', 'description' => 'Cracked', 'amount' => 800, 'service_charges' => 500, 'products_charges' => 300, 'discount' => 0, 'total' => 800]));

        $this->assertBalances([Chart::RECEIVABLE => 2350, Chart::SERVICE_INCOME => -1550, Chart::REPAIR_INCOME => -800]);
        $this->assertEqualsWithDelta(CustomerDuesStatement::for($this->customer->fresh())->total(), 2350, 0.001);
        $this->assertHealthy();
    }

    public function test_a_later_payment_moves_money_on_the_records_own_date(): void
    {
        $load = BalanceLoad::create($this->module(['network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500]));
        $this->balances();

        RecordModulePayment::handle($load, 200);

        $this->assertBalances([Chart::RECEIVABLE => 300, Chart::CLEARING => -500, Chart::NOT_CLASSIFIED => 200]);
        $this->assertHealthy();
    }

    public function test_cancelling_a_paid_service_reverses_it_and_says_so(): void
    {
        $load = BalanceLoad::create($this->module(['network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500, 'payment_status' => 'paid', 'amount_paid' => 500]));
        $this->balances();

        CancelBalanceLoad::handle($load, auth()->id());

        $this->assertBalances([]);
        $this->assertStringContainsString('cash assumed returned', LedgerEntry::latest('id')->first()->description);
        $this->assertHealthy();
    }

    // ── Payroll ────────────────────────────────────────────────────────

    protected function employee(array $attributes = []): Employee
    {
        return Employee::create($attributes + ['shop_id' => $this->theShop->id, 'name' => 'Ali', 'salary_type' => 'fixed', 'rate' => 30000]);
    }

    protected function draft(Employee $employee, array $fields = []): Payslip
    {
        Payroll::generate($this->theShop->id, '2026-11', null, auth()->id());
        $payslip = Payslip::where('employee_id', $employee->id)->sole();

        return $fields === [] ? $payslip : Payroll::saveDraft($payslip, $fields + ['rate' => $payslip->rate, 'days' => null, 'advance_recovery' => $payslip->advance_recovery, 'lines' => []]);
    }

    public function test_a_draft_posts_nothing_and_paying_posts_salary_cost_against_cash(): void
    {
        $payslip = $this->draft($this->employee());
        $this->assertBalances([]);

        Payroll::pay($payslip, now()->toDateString(), null, auth()->id());

        $this->assertBalances([Chart::SALARIES => 30000, Chart::CASH => -30000]);
        $this->assertHealthy();
    }

    public function test_salaries_count_once_though_paying_also_makes_an_expense(): void
    {
        Payroll::pay($this->draft($this->employee()), now()->toDateString(), null, auth()->id());

        $this->assertSame(1, Expense::count());
        $this->assertArrayNotHasKey(Chart::EXPENSES, $this->balances());
        $this->assertSame(30000.0, $this->balances()[Chart::SALARIES]);
    }

    public function test_recovery_and_held_amounts_split_the_cash_not_the_cost(): void
    {
        $employee = $this->employee();
        Payroll::recordAdvance($employee, AdvanceType::Given, 5000, now()->toDateString(), null, null, auth()->id());
        $this->assertBalances([Chart::EMPLOYEE_ADVANCES => 5000, Chart::CASH => -5000]);

        $held = DeductionType::create(['shop_id' => $this->theShop->id, 'name' => 'Withholding', 'reduces_cost' => false]);
        $fine = DeductionType::create(['shop_id' => $this->theShop->id, 'name' => 'Fine', 'reduces_cost' => true]);

        $payslip = $this->draft($employee, ['advance_recovery' => 5000, 'lines' => [
            ['kind' => 'deduction', 'amount' => 1000, 'deduction_type_id' => $held->id],
            ['kind' => 'deduction', 'amount' => 500, 'deduction_type_id' => $fine->id],
        ]]);

        Payroll::pay($payslip, now()->toDateString(), null, auth()->id());

        // Cost 29,500 (the fine lowers it). Cash 23,500. Advance 5,000 recovered. 1,000 held.
        $this->assertBalances([Chart::SALARIES => 29500, Chart::CASH => -5000 - 23500, Chart::WITHHOLDINGS => -1000]);
        $this->assertHealthy();
    }

    public function test_cancelling_a_paid_payslip_reverses_everything_and_says_cash_assumed_returned(): void
    {
        $employee = $this->employee();
        Payroll::recordAdvance($employee, AdvanceType::Given, 5000, now()->toDateString(), null, null, auth()->id());
        $paid = Payroll::pay($this->draft($employee), now()->toDateString(), null, auth()->id());
        $this->balances();

        Payroll::cancel($paid, auth()->id());

        $this->assertBalances([Chart::EMPLOYEE_ADVANCES => 5000, Chart::CASH => -5000]);
        $this->assertStringContainsString('cash assumed returned', LedgerEntry::where('source_type', 'payslip')->latest('id')->first()->description);
        $this->assertHealthy();
    }

    public function test_paying_from_a_shop_account_uses_its_own_ledger_account(): void
    {
        $account = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);

        Payroll::pay($this->draft($this->employee()), now()->toDateString(), $account->id, auth()->id());

        $this->assertSame(-30000.0, $this->balances()[Chart::bankCode($account->id)]);
    }

    public function test_salary_lands_on_the_same_branch_as_its_expense(): void
    {
        Payroll::pay($this->draft($this->employee()), now()->toDateString(), null, auth()->id());
        $this->balances();

        $expense = Expense::withoutGlobalScopes()->sole();

        $this->assertSame($expense->branch_id, LedgerLine::where('origin_account_code', Chart::SALARIES)->sole()->branch_id);
    }

    public function test_advances_repaid_and_cancelled_reconcile(): void
    {
        $employee = $this->employee();
        $given = Payroll::recordAdvance($employee, AdvanceType::Given, 3000, now()->toDateString(), null, null, auth()->id());
        $repaid = Payroll::recordAdvance($employee, AdvanceType::Repaid, 1000, now()->toDateString(), null, null, auth()->id());

        $this->assertBalances([Chart::EMPLOYEE_ADVANCES => 2000, Chart::CASH => -2000]);
        $this->assertHealthy();

        Payroll::cancelAdvance($repaid, auth()->id());
        Payroll::cancelAdvance($given, auth()->id());
        $this->assertBalances([]);
        $this->assertHealthy();
    }

    // ── Opening, for a shop already using all this ─────────────────────

    public function test_opening_carries_counter_service_dues_advances_and_clearing_and_reconciles(): void
    {
        $shop = $this->shop(['name' => 'Established', 'ledger_enabled' => true, 'payroll_enabled' => true]);
        $customer = Customer::create(['shop_id' => $shop->id, 'name' => 'Sana', 'phone' => '03009998877']);
        BalanceLoad::create(['shop_id' => $shop->id, 'customer_id' => $customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 500, 'total' => 500, 'payment_status' => 'partial', 'amount_paid' => 200]);
        $employee = Employee::create(['shop_id' => $shop->id, 'name' => 'Ali', 'rate' => 100]);
        Payroll::recordAdvance($employee, AdvanceType::Given, 900, now()->subMonth()->toDateString(), null, null, auth()->id());

        $shop = StartLedger::handle($shop);

        $this->theShop = $shop;
        $this->assertHealthy();

        $this->assertSame(300.0, $this->balances()[Chart::RECEIVABLE]);
        $this->assertSame(900.0, $this->balances()[Chart::EMPLOYEE_ADVANCES]);
        $this->assertSame(-500.0, $this->balances()[Chart::CLEARING]);
    }
}
