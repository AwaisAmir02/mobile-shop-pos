<?php

namespace Tests\Feature;

use App\Actions\CreateStockIn;
use App\Actions\OwnerMoney;
use App\Actions\Payroll;
use App\Actions\RecordModulePayment;
use App\Actions\StartLedger;
use App\Actions\CancelExpense;
use App\Actions\UpdateExpense;
use App\Enums\AdvanceType;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\LedgerPoster;
use App\Models\BalanceLoad;
use App\Models\CounterServicePayment;
use App\Models\Customer;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\LedgerLine;
use App\Models\OwnerWithdrawal;
use App\Models\Payslip;
use App\Models\Role;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockInPayment;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\LedgerHealthReport;
use App\Services\ShopReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class LedgerPaymentSourcesTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected Shop $theShop;
    protected ShopAccount $meezan;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->theShop = StartLedger::handle($this->shop(['ledger_enabled' => true, 'payroll_enabled' => true, 'services_enabled' => true]));
        $this->meezan = ShopAccount::create(['shop_id' => $this->theShop->id, 'name' => 'Meezan', 'provider_type' => 'bank']);
        $this->customer = Customer::create(['shop_id' => $this->theShop->id, 'name' => 'Bilal', 'phone' => '03001234567']);
    }

    protected function balance(string $code): float
    {
        app(LedgerPoster::class)->drain();

        return (float) LedgerLine::query()->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_lines.ledger_account_id')->where('ledger_accounts.code', $code)->sum('amount_paise') / 100;
    }

    protected function assertHealthy(): void
    {
        app(LedgerPoster::class)->drain();

        foreach ((new LedgerHealthReport($this->theShop->fresh()))->checks() as $check) {
            $this->assertLessThanOrEqual($check['tolerance'], abs($check['unexplained']), "{$check['label']}: ledger {$check['ledger']}, records {$check['records']}, explained {$check['explained']}");
        }

        $this->assertSame(0, (int) LedgerLine::sum('amount_paise'));
    }

    // ── Hidden unless the ledger is on ─────────────────────────────────

    public function test_shops_without_the_ledger_see_no_new_field_anywhere(): void
    {
        $this->shop(['name' => 'Plain', 'services_enabled' => true]);

        foreach (['expenses.index', 'stock-ins.history', 'balance-loads.create', 'balance-loads.history', 'wallet-loads.create', 'bills.create', 'sim-sales.create', 'repairs.create', 'nadra-verifications.create', 'services.create'] as $screen) {
            Livewire::test($screen)->assertDontSeeHtml('wire:model="paidFrom"');
        }
    }

    public function test_ledger_shops_see_it_where_money_moves(): void
    {
        foreach (['expenses.index', 'stock-ins.history', 'stock-ins.create', 'balance-loads.create', 'balance-loads.history', 'wallet-loads.create', 'wallet-loads.history', 'bills.create', 'bills.history', 'sim-sales.create', 'sim-sales.history', 'repairs.create', 'repairs.history', 'nadra-verifications.create', 'nadra-verifications.history', 'services.create', 'services.history'] as $screen) {
            Livewire::test($screen)->assertSeeHtml('wire:model="paidFrom"');
        }
    }

    // ── Sources reach the right account ────────────────────────────────

    public function test_an_expense_paid_in_cash_or_from_an_account_or_unrecorded(): void
    {
        foreach (['cash' => Chart::CASH, (string) $this->meezan->id => Chart::bankCode($this->meezan->id), '' => Chart::NOT_CLASSIFIED] as $paidFrom => $code) {
            Livewire::test('expenses.index')->set('description', 'Rent')->set('amount', '100')->set('paidFrom', (string) $paidFrom)->call('save')->assertHasNoErrors();

            $this->assertSame(-100.0, $this->balance($code), $code);
        }

        $this->assertHealthy();
    }

    public function test_a_forged_account_means_not_recorded(): void
    {
        $other = $this->shop(['name' => 'Other']);
        $theirs = ShopAccount::create(['shop_id' => $other->id, 'name' => 'Theirs', 'provider_type' => 'bank']);
        $this->actingAs(User::where('shop_id', $this->theShop->id)->first());

        Livewire::test('expenses.index')->set('description', 'Rent')->set('amount', '100')->set('paidFrom', (string) $theirs->id)->call('save');

        $this->assertNull(Expense::sole()->shop_account_id);
        $this->assertSame(-100.0, $this->balance(Chart::NOT_CLASSIFIED));
    }

    public function test_vendor_payments_at_purchase_and_later_use_their_source(): void
    {
        $vendor = Vendor::create(['shop_id' => $this->theShop->id, 'name' => 'Acme']);
        $product = $this->piece($this->theShop, ['cost_price' => null]);

        $batch = CreateStockIn::handle([['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 100.0, 'selling_price' => 150.0]], $vendor->id, 'partial', 300.0, now()->toDateString(), null, auth()->id(), paymentMethod: 'cash')->first();
        $this->assertSame(-300.0, $this->balance(Chart::CASH));

        Livewire::test('stock-ins.history')->call('openAddPayment', $batch->id)->set('paymentAmount', '200')->set('paidFrom', (string) $this->meezan->id)->call('submitPayment')->assertHasNoErrors();

        $this->assertSame(-200.0, $this->balance(Chart::bankCode($this->meezan->id)));
        $this->assertSame($this->meezan->id, StockInPayment::latest('id')->first()->shop_account_id);
        $this->assertHealthy();
    }

    public function test_udhaar_lent_from_an_account_and_repaid_in_cash(): void
    {
        Livewire::test('udhaar.show', ['customer' => $this->customer])
            ->call('openAddTransaction')
            ->set('type', 'given')->set('amount', '500')->set('paidFrom', (string) $this->meezan->id)
            ->call('save')->assertHasNoErrors();

        $this->assertSame(-500.0, $this->balance(Chart::bankCode($this->meezan->id)));

        Livewire::test('udhaar.show', ['customer' => $this->customer])
            ->call('openAddTransaction')
            ->set('type', 'repayment')->set('amount', '200')->set('paidFrom', 'cash')
            ->call('save')->assertHasNoErrors();

        $this->assertSame(200.0, $this->balance(Chart::CASH));
        $this->assertHealthy();
    }

    public function test_udhaar_given_with_nothing_chosen_is_still_cash_lent(): void
    {
        UdhaarTransaction::create(['shop_id' => $this->theShop->id, 'customer_id' => $this->customer->id, 'user_id' => auth()->id(), 'type' => 'given', 'amount' => 500, 'transaction_date' => now()->toDateString()]);

        $this->assertSame(-500.0, $this->balance(Chart::CASH));
    }

    // ── Counter services keep earlier money where it went ──────────────

    public function test_a_later_payment_by_another_method_does_not_relabel_earlier_money(): void
    {
        $load = BalanceLoad::create(['shop_id' => $this->theShop->id, 'user_id' => auth()->id(), 'customer_id' => $this->customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 1000, 'total' => 1000, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        Livewire::test('balance-loads.history')->call('openAddPayment', $load->id)->set('paymentAmount', '300')->set('paidFrom', 'cash')->call('submitPayment')->assertHasNoErrors();
        Livewire::test('balance-loads.history')->call('openAddPayment', $load->id)->set('paymentAmount', '200')->set('paidFrom', (string) $this->meezan->id)->call('submitPayment')->assertHasNoErrors();

        $this->assertSame(300.0, $this->balance(Chart::CASH));
        $this->assertSame(200.0, $this->balance(Chart::bankCode($this->meezan->id)));
        $this->assertSame(500.0, $this->balance(Chart::RECEIVABLE));
        $this->assertSame(500.0, (float) $load->fresh()->amount_paid, 'The figure every screen uses is unchanged.');
        $this->assertHealthy();
    }

    public function test_money_with_no_recorded_source_stays_not_classified_alongside_money_with_one(): void
    {
        $load = BalanceLoad::create(['shop_id' => $this->theShop->id, 'user_id' => auth()->id(), 'customer_id' => $this->customer->id, 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 1000, 'total' => 1000, 'payment_status' => 'partial', 'amount_paid' => 100]);

        Livewire::test('balance-loads.history')->call('openAddPayment', $load->id)->set('paymentAmount', '300')->set('paidFrom', 'cash')->call('submitPayment');
        RecordModulePayment::handle($load->fresh(), 50);

        $this->assertSame(300.0, $this->balance(Chart::CASH));
        $this->assertSame(150.0, $this->balance(Chart::NOT_CLASSIFIED));
        $this->assertHealthy();
    }

    public function test_the_create_screen_records_where_money_was_received(): void
    {
        Livewire::test('balance-loads.create')
            ->set('networkChoice', 'Jazz')->set('loadType', 'balance')->set('phoneNumber', '03001234567')
            ->set('amount', '500')->set('fee', '0')->set('discount', '0')->set('amountReceivedNow', '500')->set('paidFrom', 'cash')
            ->call('save')->assertHasNoErrors();

        $row = CounterServicePayment::sole();
        $this->assertSame('balance_load', $row->source_type);
        $this->assertSame(500.0, $this->balance(Chart::CASH));
        $this->assertHealthy();
    }

    public function test_without_the_ledger_no_source_rows_are_written(): void
    {
        $this->shop(['name' => 'Plain']);
        $load = BalanceLoad::create(['user_id' => auth()->id(), 'network' => 'Jazz', 'load_type' => 'balance', 'amount' => 100, 'total' => 100, 'payment_status' => 'unpaid', 'amount_paid' => 0]);

        Livewire::test('balance-loads.history')->call('openAddPayment', $load->id)->set('paymentAmount', '50')->set('paidFrom', 'cash')->call('submitPayment');

        $this->assertSame(0, CounterServicePayment::count());
        $this->assertSame(50.0, (float) $load->fresh()->amount_paid);
    }

    // ── Owner withdrawals ──────────────────────────────────────────────

    public function test_an_owner_withdrawal_is_drawings_not_a_cost_and_leaves_net_alone(): void
    {
        $summary = fn () => app(ShopReportService::class)->summary($this->theShop, now()->startOfMonth(), now()->endOfMonth())['netSummary'];
        $net = $summary();

        Livewire::test('ledger.owner-money')->set('amount', '5000')->set('note', 'Home')->call('save')->assertHasNoErrors()->assertSee('Rs 5,000.00');

        $this->assertSame(5000.0, $this->balance(Chart::DRAWINGS));
        $this->assertSame(-5000.0, $this->balance(Chart::CASH));
        $this->assertSame(0, Expense::count());
        $this->assertSame($net, $summary());
        $this->assertHealthy();
    }

    public function test_cancelling_a_withdrawal_reverses_it(): void
    {
        $page = Livewire::test('ledger.owner-money')->set('amount', '5000')->call('save');

        $page->call('cancel', 'withdrawal', OwnerWithdrawal::sole()->id)->assertHasNoErrors()->assertSee('Cancelled');

        $this->assertSame(0.0, $this->balance(Chart::DRAWINGS));
    }

    public function test_it_is_owner_only_and_needs_the_ledger_running(): void
    {
        $role = Role::create(['shop_id' => $this->theShop->id, 'name' => 'M', 'permissions' => ['expenses']]);
        $this->actingAs(User::factory()->create(['shop_id' => $this->theShop->id, 'is_owner' => false, 'role_id' => $role->id]));
        $this->get(route('ledger.owner-money'))->assertForbidden();

        $notStarted = $this->shop(['name' => 'New', 'ledger_enabled' => true]);
        $this->assertRefused(fn () => OwnerMoney::withdraw($notStarted, 100, now()->toDateString(), 'cash', null, null, auth()->id()), 'ledger running');
        Livewire::test('ledger.owner-money')->assertSee("The ledger isn't running");
    }

    // ── Held salary ────────────────────────────────────────────────────

    protected function paidWithHeld(float $held = 1000): void
    {
        $employee = Employee::create(['shop_id' => $this->theShop->id, 'name' => 'Ali', 'rate' => 30000]);
        $type = DeductionType::create(['shop_id' => $this->theShop->id, 'name' => 'Withholding', 'reduces_cost' => false]);
        Payroll::generate($this->theShop->id, '2026-11', null, auth()->id());
        $draft = Payroll::saveDraft(Payslip::sole(), ['rate' => 30000, 'days' => null, 'advance_recovery' => 0, 'lines' => [['kind' => 'deduction', 'amount' => $held, 'deduction_type_id' => $type->id]]]);
        Payroll::pay($draft, now()->toDateString(), null, auth()->id());
    }

    public function test_paying_over_held_salary_clears_the_liability_and_costs_nothing_more(): void
    {
        $this->paidWithHeld();
        $this->assertSame(-1000.0, $this->balance(Chart::WITHHOLDINGS));
        $salaries = $this->balance(Chart::SALARIES);

        Livewire::test('ledger.owner-money')->set('kind', 'withheld')->assertSee('Rs 1,000.00 is being held')->set('amount', '600')->set('paidFrom', (string) $this->meezan->id)->call('save')->assertHasNoErrors();

        $this->assertSame(-400.0, $this->balance(Chart::WITHHOLDINGS));
        $this->assertSame(-600.0, $this->balance(Chart::bankCode($this->meezan->id)));
        $this->assertSame($salaries, $this->balance(Chart::SALARIES));
        $this->assertHealthy();
    }

    public function test_you_cannot_pay_over_more_than_is_held(): void
    {
        $this->paidWithHeld();

        $this->assertRefused(fn () => OwnerMoney::payOverWithheld($this->theShop, 1000.01, now()->toDateString(), 'cash', null, null, auth()->id()), 'Only Rs 1,000.00');
    }

    // ── Writing off an advance ─────────────────────────────────────────

    protected function advance(float $amount = 5000): Employee
    {
        $employee = Employee::create(['shop_id' => $this->theShop->id, 'name' => 'Ali', 'rate' => 30000]);
        Payroll::recordAdvance($employee, AdvanceType::Given, $amount, now()->toDateString(), null, null, auth()->id());

        return $employee;
    }

    public function test_a_write_off_costs_the_shop_clears_the_advance_and_moves_no_cash(): void
    {
        $employee = $this->advance();
        $cash = $this->balance(Chart::CASH);

        Payroll::writeOffAdvance($employee, 2000, now()->toDateString(), 'Left the shop', auth()->id());

        $this->assertSame(3000.0, $employee->advanceBalance());
        $this->assertSame(3000.0, $this->balance(Chart::EMPLOYEE_ADVANCES));
        $this->assertSame(2000.0, $this->balance(Chart::EXPENSES));
        $this->assertSame($cash, $this->balance(Chart::CASH), 'No cash moved.');
        $this->assertSame(0.0, $this->balance(Chart::NOT_CLASSIFIED), 'And its Expense did not post cash either.');
        $this->assertHealthy();
    }

    public function test_a_write_off_reaches_net_through_its_expense_which_is_locked(): void
    {
        $employee = $this->advance();
        Payroll::writeOffAdvance($employee, 2000, now()->toDateString(), null, auth()->id());

        $expense = Expense::sole();
        $this->assertSame(2000.0, (float) $expense->amount);
        $this->assertSame(-2000.0, (float) app(ShopReportService::class)->summary($this->theShop, now()->startOfMonth(), now()->endOfMonth())['netSummary']);

        $this->assertRefused(fn () => UpdateExpense::handle($expense, 'x', 1.0, now()->toDateString(), auth()->id()));
        $this->assertRefused(fn () => CancelExpense::handle($expense, auth()->id()));
    }

    public function test_cancelling_a_write_off_restores_the_advance_and_the_expense(): void
    {
        $employee = $this->advance();
        $row = Payroll::writeOffAdvance($employee, 2000, now()->toDateString(), null, auth()->id());
        app(LedgerPoster::class)->drain();

        Payroll::cancelAdvance($row, auth()->id());

        $this->assertSame(5000.0, $employee->advanceBalance());
        $this->assertSame(0, Expense::count());
        $this->assertTrue(Expense::withoutGlobalScope(ExcludesCancelledScope::class)->sole()->isCancelled());
        $this->assertSame(5000.0, $this->balance(Chart::EMPLOYEE_ADVANCES));
        $this->assertSame(0.0, $this->balance(Chart::EXPENSES));
        $this->assertHealthy();
    }

    public function test_write_off_is_capped_and_needs_the_ledger(): void
    {
        $employee = $this->advance(100);

        $this->assertRefused(fn () => Payroll::writeOffAdvance($employee, 101, now()->toDateString(), null, auth()->id()), 'only owes');

        $plain = $this->shop(['name' => 'Plain', 'payroll_enabled' => true]);
        $theirs = Employee::create(['shop_id' => $plain->id, 'name' => 'Sara', 'rate' => 1]);
        Payroll::recordAdvance($theirs, AdvanceType::Given, 50, now()->toDateString(), null, null, auth()->id());

        $this->assertRefused(fn () => Payroll::writeOffAdvance($theirs, 50, now()->toDateString(), null, auth()->id()), 'ledger running');
    }

    public function test_the_write_off_button_only_shows_with_the_ledger_running(): void
    {
        $employee = $this->advance();

        Livewire::test('payroll.advances', ['employee' => (string) $employee->id])
            ->assertSee('Write Off')
            ->call('openForm', 'written_off')
            ->set('advanceAmount', '1000')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(4000.0, $employee->advanceBalance());
        $this->assertSame('written_off', EmployeeAdvance::latest('id')->first()->type->value);

        $plain = $this->shop(['name' => 'Plain', 'payroll_enabled' => true]);
        $theirs = Employee::create(['shop_id' => $plain->id, 'name' => 'Sara', 'rate' => 1]);
        Payroll::recordAdvance($theirs, AdvanceType::Given, 50, now()->toDateString(), null, null, auth()->id());
        Livewire::test('payroll.advances', ['employee' => (string) $theirs->id])->assertDontSee('Write Off');
    }

    public function test_a_given_or_repaid_row_can_never_be_typed_as_a_write_off_through_the_ordinary_path(): void
    {
        $employee = $this->advance();

        $this->expectException(\LogicException::class);
        Payroll::recordAdvance($employee, AdvanceType::WrittenOff, 1, now()->toDateString(), null, null, auth()->id());
    }
}
