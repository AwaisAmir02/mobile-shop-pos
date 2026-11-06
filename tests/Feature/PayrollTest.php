<?php

namespace Tests\Feature;

use App\Actions\CancelExpense;
use App\Actions\Payroll;
use App\Actions\UpdateExpense;
use App\Enums\AdvanceType;
use App\Enums\FeatureKey;
use App\Enums\PayslipStatus;
use App\Enums\SalaryType;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\DeductionType;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\ModuleRevision;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\User;
use App\ReportQueries\PayrollReport;
use App\Services\Billing\Entitlements;
use App\Services\PayslipPdfService;
use App\Services\ShopReportService;
use App\Support\BranchContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MeasuredFixtures;
use Tests\TestCase;

class PayrollTest extends TestCase
{
    use MeasuredFixtures, RefreshDatabase;

    protected string $month = '2026-11';

    protected function payrollShop(array $attributes = []): Shop
    {
        return $this->shop($attributes + ['payroll_enabled' => true]);
    }

    protected function employee(Shop $shop, array $attributes = []): Employee
    {
        return Employee::create($attributes + ['shop_id' => $shop->id, 'name' => 'Ali', 'salary_type' => 'fixed', 'rate' => 30000]);
    }

    protected function draftFor(Employee $employee): Payslip
    {
        Payroll::generate($employee->shop_id, $this->month, null, auth()->id());

        return Payslip::where('employee_id', $employee->id)->sole();
    }

    protected function save(Payslip $payslip, array $fields = []): Payslip
    {
        return Payroll::saveDraft($payslip, $fields + ['rate' => $payslip->rate, 'days' => $payslip->days, 'advance_recovery' => $payslip->advance_recovery, 'lines' => []]);
    }

    protected function pay(Payslip $payslip): Payslip
    {
        return Payroll::pay($payslip, now()->toDateString(), null, auth()->id());
    }

    protected function give(Employee $employee, float $amount): EmployeeAdvance
    {
        return Payroll::recordAdvance($employee, AdvanceType::Given, $amount, now()->toDateString(), null, null, auth()->id());
    }

    protected function net(Shop $shop): float
    {
        return app(ShopReportService::class)->summary($shop, now()->startOfMonth(), now()->endOfMonth())['netSummary'];
    }

    // ── Employees ──────────────────────────────────────────────────────

    public function test_an_employee_needs_no_login_and_uses_no_staff_place(): void
    {
        $shop = $this->payrollShop();
        $before = app(Entitlements::class)->usage($shop, \App\Enums\LimitKey::StaffUsers);

        $employee = $this->employee($shop);

        $this->assertNull($employee->user_id);
        $this->assertNull($employee->fresh()->branch_id);
        $this->assertSame($before, app(Entitlements::class)->usage($shop, \App\Enums\LimitKey::StaffUsers));
    }

    public function test_a_login_links_once_and_deleting_it_keeps_the_employee(): void
    {
        $shop = $this->payrollShop();
        $login = User::factory()->create(['shop_id' => $shop->id]);
        $employee = $this->employee($shop, ['user_id' => $login->id]);

        Livewire::test('payroll.employees')
            ->call('openCreate')->set('name', 'Sara')->set('rate', '100')->set('userId', (string) $login->id)
            ->call('save')->assertHasErrors('userId');

        $login->delete();

        $this->assertNull($employee->fresh()->user_id);
    }

    public function test_someone_with_payroll_history_can_only_be_deactivated(): void
    {
        $shop = $this->payrollShop();
        $paid = $this->employee($shop);
        $this->pay($this->draftFor($paid));
        $fresh = $this->employee($shop, ['name' => 'New']);

        $page = Livewire::test('payroll.employees')->call('delete', $paid->id);
        $this->assertNotNull($paid->fresh());

        $page->call('toggleActive', $paid->id);
        $this->assertFalse($paid->fresh()->is_active);

        $page->call('delete', $fresh->id);
        $this->assertNull($fresh->fresh());
    }

    // ── Payslips ───────────────────────────────────────────────────────

    public function test_generating_creates_drafts_for_active_people_and_only_once(): void
    {
        $shop = $this->payrollShop();
        $ali = $this->employee($shop);
        $this->employee($shop, ['name' => 'Gone', 'is_active' => false]);

        $this->assertSame(1, Payroll::generate($shop->id, $this->month, null, auth()->id()));
        $this->assertSame(0, Payroll::generate($shop->id, $this->month, null, auth()->id()));

        $payslip = Payslip::sole();
        $this->assertSame($ali->id, $payslip->employee_id);
        $this->assertSame(PayslipStatus::Draft, $payslip->status);
        $this->assertSame('2026-11-01', $payslip->period->toDateString());
        $this->assertSame(30000.0, (float) $payslip->net_pay);
        $this->assertSame(0, Expense::count());
        $this->assertMatchesRegularExpression('/^PS-\d{6}$/', $payslip->number());
    }

    public function test_the_rate_is_snapshotted_so_a_raise_never_rewrites_history(): void
    {
        $employee = $this->employee($this->payrollShop());
        $payslip = $this->pay($this->draftFor($employee));

        $employee->update(['rate' => 50000, 'name' => 'Renamed']);

        $fresh = $payslip->fresh();
        $this->assertSame(30000.0, (float) $fresh->rate);
        $this->assertSame('Ali', $fresh->employee_name);
    }

    public function test_daily_wage_is_rate_times_days_typed_on_the_run(): void
    {
        $employee = $this->employee($this->payrollShop(), ['salary_type' => 'daily', 'rate' => 1500]);
        $draft = $this->draftFor($employee);

        $this->assertSame(0.0, (float) $draft->net_pay);
        $this->assertRefused(fn () => $this->pay($draft), 'nothing to be paid');

        $saved = $this->save($draft, ['days' => 22.5]);

        $this->assertSame(33750.0, (float) $saved->base_pay);
        $this->assertSame(33750.0, (float) $this->pay($saved)->salary_cost);
    }

    public function test_extra_earnings_are_free_text_and_add_to_cost(): void
    {
        $saved = $this->save($this->draftFor($this->employee($this->payrollShop())), ['lines' => [['kind' => 'earning', 'label' => 'Commission', 'amount' => 2500]]]);

        $this->assertSame(32500.0, $saved->grossEarnings());
        $this->assertSame(32500.0, (float) $saved->salary_cost);
        $this->assertSame(32500.0, (float) $saved->net_pay);
        $this->assertSame('Commission', $saved->lines->first()->label);
    }

    public function test_a_cost_reducing_deduction_lowers_cost_and_a_held_one_does_not(): void
    {
        $shop = $this->payrollShop();
        $fine = DeductionType::create(['shop_id' => $shop->id, 'name' => 'Fine', 'reduces_cost' => true]);
        $held = DeductionType::create(['shop_id' => $shop->id, 'name' => 'Withholding', 'reduces_cost' => false]);

        $saved = $this->save($this->draftFor($this->employee($shop)), ['lines' => [
            ['kind' => 'deduction', 'amount' => 1000, 'deduction_type_id' => $fine->id],
            ['kind' => 'deduction', 'amount' => 500, 'deduction_type_id' => $held->id],
        ]]);

        $this->assertSame(29000.0, (float) $saved->salary_cost);
        $this->assertSame(28500.0, (float) $saved->net_pay);
        $this->assertSame(29000.0, (float) $this->pay($saved)->expense->amount);
    }

    public function test_a_default_deduction_lands_on_new_drafts_and_can_be_changed(): void
    {
        $shop = $this->payrollShop();
        $type = DeductionType::create(['shop_id' => $shop->id, 'name' => 'Welfare', 'reduces_cost' => false, 'default_amount' => 200]);

        $draft = $this->draftFor($this->employee($shop));

        $this->assertSame(29800.0, (float) $draft->net_pay);

        $saved = $this->save($draft, ['lines' => [['kind' => 'deduction', 'amount' => 300, 'deduction_type_id' => $type->id]]]);
        $this->assertSame(29700.0, (float) $saved->net_pay);
    }

    public function test_deductions_cannot_exceed_pay(): void
    {
        $shop = $this->payrollShop();
        $fine = DeductionType::create(['shop_id' => $shop->id, 'name' => 'Fine', 'reduces_cost' => true]);

        $this->assertRefused(fn () => $this->save($this->draftFor($this->employee($shop)), ['lines' => [['kind' => 'deduction', 'amount' => 40000, 'deduction_type_id' => $fine->id]]]), 'more than their pay');
    }

    public function test_only_a_draft_can_be_edited_and_a_payslip_paid_once(): void
    {
        $paid = $this->pay($this->draftFor($this->employee($this->payrollShop())));

        $this->assertRefused(fn () => $this->save($paid), 'Only a draft');
        $this->assertRefused(fn () => $this->pay($paid), 'already paid');
        $this->assertSame(1, Expense::count());
    }

    public function test_the_database_itself_allows_one_active_payslip_per_person_per_month(): void
    {
        $employee = $this->employee($this->payrollShop());
        $first = $this->draftFor($employee);

        $this->expectException(QueryException::class);
        $copy = $first->replicate();
        $copy->save();
    }

    public function test_cancelling_frees_the_month_for_a_new_payslip(): void
    {
        $employee = $this->employee($this->payrollShop());
        $first = $this->pay($this->draftFor($employee));

        Payroll::cancel($first, auth()->id());

        $this->assertSame(1, Payroll::generate($employee->shop_id, $this->month, null, auth()->id()));
        $this->assertSame(1, Payslip::count());
        $this->assertSame(2, Payslip::withoutGlobalScope(ExcludesCancelledScope::class)->count());
    }

    // ── Net ────────────────────────────────────────────────────────────

    public function test_paying_adds_exactly_one_expense_dated_on_the_paid_date_and_net_just_reads_it(): void
    {
        $shop = $this->payrollShop();
        $employee = $this->employee($shop);
        $before = $this->net($shop);

        $payslip = Payroll::pay($this->draftFor($employee), now()->toDateString(), null, auth()->id());

        $expense = Expense::sole();
        $this->assertSame($payslip->id, $expense->payslip_id);
        $this->assertSame(30000.0, (float) $expense->amount);
        $this->assertSame(now()->toDateString(), $expense->expense_date->toDateString());
        $this->assertSame('Salary: Ali, Nov 2026', $expense->description);
        $this->assertSame($before - 30000.0, $this->net($shop));
    }

    public function test_a_late_payment_lands_in_the_month_it_was_paid(): void
    {
        $shop = $this->payrollShop();
        $payslip = Payroll::pay($this->draftFor($this->employee($shop)), now()->subDays(40)->toDateString(), null, auth()->id());

        $this->assertSame(now()->subDays(40)->toDateString(), $payslip->expense->expense_date->toDateString());
        $this->assertSame(0.0, $this->net($shop));
    }

    public function test_advance_recovery_does_not_reduce_the_expense_only_the_cash(): void
    {
        $shop = $this->payrollShop();
        $employee = $this->employee($shop);
        $this->give($employee, 5000);
        $this->assertSame(0.0, $this->net($shop), 'An advance is not an expense.');
        $this->assertSame(0, Expense::count());

        $draft = $this->draftFor($employee);
        $this->assertSame(5000.0, (float) $draft->advance_recovery, 'Suggested towards the balance.');

        $paid = $this->pay($draft);

        $this->assertSame(25000.0, (float) $paid->net_pay);
        $this->assertSame(30000.0, (float) $paid->expense->amount);
        $this->assertSame(-30000.0, $this->net($shop));
        $this->assertSame(0.0, $employee->advanceBalance());
    }

    public function test_cancelling_a_paid_payslip_undoes_the_expense_and_the_advance_and_keeps_a_revision(): void
    {
        $shop = $this->payrollShop();
        $employee = $this->employee($shop);
        $this->give($employee, 5000);
        $paid = $this->pay($this->draftFor($employee));

        Payroll::cancel($paid, auth()->id(), 'Wrong month');

        $this->assertSame(0, Expense::count());
        $this->assertTrue(Expense::withoutGlobalScope(ExcludesCancelledScope::class)->sole()->isCancelled());
        $this->assertSame(0.0, $this->net($shop));
        $this->assertSame(5000.0, $employee->advanceBalance());

        $revision = ModuleRevision::where('revisionable_type', Payslip::class)->sole();
        $this->assertSame('Wrong month', $revision->reason);
        $this->assertSame('paid', $revision->snapshot['status']);
        $this->assertEquals(30000, $revision->snapshot['rate']);
    }

    public function test_a_linked_expense_cannot_be_changed_on_its_own(): void
    {
        $this->pay($this->draftFor($this->employee($this->payrollShop())));
        $expense = Expense::sole();

        $this->assertRefused(fn () => UpdateExpense::handle($expense, 'x', 1.0, now()->toDateString(), auth()->id()), 'Cancel the payslip');
        $this->assertRefused(fn () => CancelExpense::handle($expense, auth()->id()), 'Cancel the payslip');
        $this->assertSame(30000.0, (float) $expense->fresh()->amount);

        Livewire::test('expenses.index')->assertSee('From Payroll')->assertDontSee("openCancel({$expense->id})");
    }

    public function test_ordinary_expenses_still_edit_and_cancel(): void
    {
        $shop = $this->payrollShop();
        $expense = Expense::create(['shop_id' => $shop->id, 'description' => 'Rent', 'amount' => 100, 'expense_date' => now()->toDateString()]);

        UpdateExpense::handle($expense, 'Rent', 200.0, now()->toDateString(), auth()->id());
        CancelExpense::handle($expense->fresh(), auth()->id());

        $this->assertSame(0, Expense::count());
    }

    // ── Advances ───────────────────────────────────────────────────────

    public function test_the_advance_ledger_tracks_given_repaid_and_cancelled(): void
    {
        $employee = $this->employee($this->payrollShop());
        $big = $this->give($employee, 5000);
        $this->give($employee, 1000);

        $repaid = Payroll::recordAdvance($employee, AdvanceType::Repaid, 4500, now()->toDateString(), null, null, auth()->id());
        $this->assertSame(1500.0, $employee->advanceBalance());

        $this->assertRefused(fn () => Payroll::recordAdvance($employee, AdvanceType::Repaid, 2000, now()->toDateString(), null, null, auth()->id()), 'only owes');
        $this->assertRefused(fn () => Payroll::cancelAdvance($big, auth()->id()), 'already been repaid');

        Payroll::cancelAdvance($repaid, auth()->id());
        $this->assertSame(6000.0, $employee->advanceBalance());
        $this->assertSame(0, Expense::count());
    }

    public function test_recovery_is_editable_for_instalments_and_capped_at_the_balance(): void
    {
        $employee = $this->employee($this->payrollShop());
        $this->give($employee, 5000);
        $draft = $this->draftFor($employee);

        $this->assertRefused(fn () => $this->save($draft, ['advance_recovery' => 6000]), 'only owes');

        $saved = $this->save($draft, ['advance_recovery' => 2000]);
        $this->pay($saved);

        $this->assertSame(3000.0, $employee->advanceBalance());
    }

    public function test_paying_refuses_if_the_balance_fell_since_the_draft(): void
    {
        $employee = $this->employee($this->payrollShop());
        $this->give($employee, 5000);
        $draft = $this->draftFor($employee);

        Payroll::recordAdvance($employee, AdvanceType::Repaid, 5000, now()->toDateString(), null, null, auth()->id());

        $this->assertRefused(fn () => $this->pay($draft), 'only owes');
    }

    public function test_a_deducted_row_can_only_be_undone_through_its_payslip(): void
    {
        $employee = $this->employee($this->payrollShop());
        $this->give($employee, 5000);
        $this->pay($this->draftFor($employee));

        $this->assertRefused(fn () => Payroll::cancelAdvance(EmployeeAdvance::where('type', 'deducted')->sole(), auth()->id()), 'Cancel the payslip');
    }

    public function test_the_recovery_suggestion_never_exceeds_pay(): void
    {
        $employee = $this->employee($this->payrollShop(), ['rate' => 1000]);
        $this->give($employee, 5000);

        $draft = $this->draftFor($employee);

        $this->assertSame(1000.0, (float) $draft->advance_recovery);
        $this->assertSame(0.0, (float) $draft->net_pay);
    }

    // ── Branches ───────────────────────────────────────────────────────

    public function test_the_expense_follows_the_employees_branch_or_main(): void
    {
        $shop = $this->payrollShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $main = BranchContext::current()->mainBranchIdFor($shop->id);

        $there = $this->employee($shop, ['name' => 'There']);
        $there->branch_id = $second->id;
        $there->save();
        $shared = $this->employee($shop, ['name' => 'Shared']);

        Payroll::generate($shop->id, $this->month, null, auth()->id());
        Payslip::orderBy('id')->get()->each(fn ($p) => $this->pay($p));

        $this->assertSame($second->id, Expense::where('description', 'like', '%There%')->sole()->branch_id);
        $this->assertSame($main, Expense::where('description', 'like', '%Shared%')->sole()->branch_id);
        $this->assertNull(Payslip::where('employee_id', $shared->id)->sole()->branch_id);

        $summary = app(ShopReportService::class)->summary($shop, now()->startOfMonth(), now()->endOfMonth(), $second->id);
        $this->assertSame(30000.0, $summary['totalExpenses']);
    }

    public function test_one_run_can_be_narrowed_by_branch(): void
    {
        $shop = $this->payrollShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $this->employee($shop, ['name' => 'Shared']);
        $there = $this->employee($shop, ['name' => 'There']);
        $there->branch_id = $second->id;
        $there->save();

        $this->assertSame(1, Payroll::generate($shop->id, $this->month, $second->id, auth()->id()));
        $this->assertSame(1, Payroll::generate($shop->id, $this->month, null, auth()->id()));
    }

    public function test_branch_restricted_staff_only_see_their_own_branch(): void
    {
        $shop = $this->payrollShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        $third = Branch::create(['shop_id' => $shop->id, 'name' => 'Third']);
        BranchContext::current()->forget();
        $shared = $this->employee($shop, ['name' => 'SharedPerson']);
        $mine = $this->employee($shop, ['name' => 'MinePerson']);
        $mine->branch_id = $second->id;
        $mine->save();
        $theirs = $this->employee($shop, ['name' => 'TheirsPerson']);
        $theirs->branch_id = $third->id;
        $theirs->save();

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Manager', 'permissions' => ['payroll', 'payroll-run']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id, 'branch_id' => $second->id]));

        Livewire::test('payroll.employees')->assertSee('MinePerson')->assertDontSee('TheirsPerson')->assertDontSee('SharedPerson');
        $this->assertSame(1, Payroll::generate($shop->id, $this->month, null, auth()->id()));
    }

    // ── Plan and access ────────────────────────────────────────────────

    public function test_it_is_plan_controlled_in_growth_and_commerce_and_an_add_on_for_starter(): void
    {
        $this->assertTrue(FeatureKey::Payroll->planControlled());
        $this->assertTrue(Plan::where('name', 'Growth')->sole()->feature_flags['payroll']);
        $this->assertTrue(Plan::where('name', 'Commerce')->sole()->feature_flags['payroll']);
        $this->assertFalse(Plan::where('name', 'Starter')->sole()->feature_flags['payroll']);

        $shop = Shop::create(['name' => 'Starter', 'plan_id' => Plan::where('name', 'Starter')->sole()->id]);
        $addon = Addon::create(['name' => 'Payroll', 'kind' => 'feature_unlock', 'feature_keys' => ['payroll'], 'price' => 1000, 'billing' => 'monthly']);
        ShopAddon::create(['shop_id' => $shop->id, 'addon_id' => $addon->id, 'name' => 'Payroll', 'kind' => 'feature_unlock', 'feature_keys' => ['payroll'], 'status' => 'active', 'starts_at' => now()->subDay(), 'ends_at' => now()->addMonth(), 'price' => 1000, 'billing' => 'monthly']);

        app(Entitlements::class)->syncFeatureFlags($shop->fresh());

        $this->assertTrue($shop->fresh()->payroll_enabled);
    }

    public function test_downgrading_keeps_history_and_expenses_and_stops_new_runs(): void
    {
        $shop = $this->payrollShop();
        $employee = $this->employee($shop);
        $paid = $this->pay($this->draftFor($employee));

        $shop->update(['payroll_enabled' => false]);
        auth()->user()->unsetRelation('shop');

        $this->assertRefused(fn () => Payroll::generate($shop->id, '2026-12', null, auth()->id()), "isn't on your plan");
        $this->assertSame(1, Expense::count());

        Livewire::test('payroll.history')->assertSee($paid->number())->assertSee("isn't on your plan", false);
        Livewire::test('payroll.run')->assertDontSee('Generate Drafts');

        Payroll::cancel($paid, auth()->id());
        $this->assertSame(0, Expense::count());
    }

    public function test_only_the_owner_gets_in_until_a_role_is_given_access(): void
    {
        $shop = $this->payrollShop();

        foreach (['payroll.employees', 'payroll.advances', 'payroll.history', 'payroll.deduction-types', 'payroll.run'] as $route) {
            $this->get(route($route))->assertOk();
        }

        $none = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales', 'expenses']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $none->id]));
        $this->get(route('payroll.employees'))->assertForbidden();
        $this->get(route('payroll.run'))->assertForbidden();

        $viewer = Role::create(['shop_id' => $shop->id, 'name' => 'Viewer', 'permissions' => ['payroll']]);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $viewer->id]));
        $this->get(route('payroll.history'))->assertOk();
        $this->get(route('payroll.run'))->assertForbidden();
    }

    public function test_losing_access_stops_a_page_that_is_already_open(): void
    {
        $shop = $this->payrollShop();
        $this->employee($shop);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => ['payroll-run']]);
        $user = User::factory()->create(['shop_id' => $shop->id, 'is_owner' => false, 'role_id' => $role->id]);
        $this->actingAs($user);

        $page = Livewire::test('payroll.run');

        $role->update(['permissions' => ['sales']]);
        $user->unsetRelation('role');

        $page->call('generate')->assertStatus(403);
        $this->assertSame(0, Payslip::count());
    }

    public function test_paying_and_cancelling_work_with_another_branch_selected(): void
    {
        $shop = $this->payrollShop();
        $second = Branch::create(['shop_id' => $shop->id, 'name' => 'Second']);
        BranchContext::current()->forget();
        $this->employee($shop);
        BranchContext::current()->select($second->id);

        $paid = $this->pay($this->draftFor($this->employee($shop, ['name' => 'Sara'])));
        $this->assertSame(BranchContext::current()->mainBranchIdFor($shop->id), Expense::withoutGlobalScopes()->where('payslip_id', $paid->id)->sole()->branch_id);

        Payroll::cancel($paid, auth()->id());

        $this->assertSame('cancelled', Expense::withoutGlobalScopes()->where('payslip_id', $paid->id)->sole()->status->value);
    }

    public function test_the_links_and_header_survive_an_action_on_the_page(): void
    {
        $this->payrollShop();

        Livewire::test('payroll.deduction-types')->assertSee('Run Payroll')
            ->set('name', 'Fine')->call('save')->assertSee('Run Payroll');

        Livewire::test('payroll.deduction-types', ['embedded' => true])->assertDontSee('Run Payroll');
    }

    public function test_another_shops_payroll_is_out_of_reach(): void
    {
        $other = Shop::create(['name' => 'Other', 'payroll_enabled' => true]);
        $theirs = Employee::create(['shop_id' => $other->id, 'name' => 'TheirsPerson', 'rate' => 1]);
        $slip = Payslip::create(['shop_id' => $other->id, 'employee_id' => $theirs->id, 'period' => '2026-11-01', 'employee_name' => 'TheirsPerson', 'salary_type' => 'fixed', 'rate' => 1]);
        $otherType = DeductionType::create(['shop_id' => $other->id, 'name' => 'Theirs', 'reduces_cost' => true]);

        $shop = $this->payrollShop();
        Livewire::test('payroll.employees')->assertDontSee('TheirsPerson');
        Livewire::test('payroll.history')->assertDontSee('TheirsPerson');

        $this->assertRefused(fn () => $this->save($this->draftFor($this->employee($shop)), ['lines' => [['kind' => 'deduction', 'amount' => 1, 'deduction_type_id' => $otherType->id]]]), 'Choose a type');

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        Livewire::test('payroll.run')->call('openEdit', $slip->id);
    }

    // ── Warnings ───────────────────────────────────────────────────────

    public function test_the_first_use_warning_shows_until_dismissed(): void
    {
        $this->payrollShop();

        $page = Livewire::test('payroll.employees')->assertSee('Do you already log salaries in Expenses?');
        $page->call('dismissPayrollNotice');

        auth()->user()->unsetRelation('shop');
        Livewire::test('payroll.run')->assertDontSee('Do you already log salaries in Expenses?');
    }

    public function test_the_run_screen_lists_expenses_that_look_like_salaries_without_claiming_certainty(): void
    {
        $shop = $this->payrollShop();
        $date = now()->toDateString();
        Expense::create(['shop_id' => $shop->id, 'description' => 'SALARY for Ali', 'amount' => 100, 'expense_date' => $date]);
        Expense::create(['shop_id' => $shop->id, 'description' => 'Helper wages', 'amount' => 200, 'expense_date' => $date]);
        Expense::create(['shop_id' => $shop->id, 'description' => 'Staff salaries', 'amount' => 300, 'expense_date' => $date]);
        Expense::create(['shop_id' => $shop->id, 'description' => 'Rent', 'amount' => 400, 'expense_date' => $date]);
        Expense::create(['shop_id' => $shop->id, 'description' => 'Old salary', 'amount' => 500, 'expense_date' => now()->subMonths(3)->toDateString()]);
        $this->employee($shop);

        Livewire::test('payroll.run')
            ->assertSee('SALARY for Ali')->assertSee('Helper wages')->assertSee('Staff salaries')
            ->assertDontSee('Rent,')->assertDontSee('Old salary')
            ->assertSee('so it can miss entries or flag unrelated ones')
            ->call('generate');

        // A payroll expense doesn't warn about itself.
        Payslip::orderBy('id')->get()->each(fn ($p) => $this->pay($p));
        Livewire::test('payroll.run')->assertDontSee('Salary: Ali');
    }

    // ── Screens ────────────────────────────────────────────────────────

    public function test_the_full_flow_through_the_screens(): void
    {
        $shop = $this->payrollShop();
        $type = DeductionType::create(['shop_id' => $shop->id, 'name' => 'Unpaid leave', 'reduces_cost' => true]);

        Livewire::test('payroll.employees')
            ->call('openCreate')->set('name', 'Bilal')->set('salaryType', 'daily')->assertSee('Daily Rate')->set('rate', '1000')
            ->call('save')->assertHasNoErrors()->assertSee('Bilal');

        $employee = Employee::sole();
        $this->assertSame(SalaryType::Daily, $employee->salary_type);

        Livewire::test('payroll.advances', ['employee' => (string) $employee->id])
            ->call('openForm', 'given')->set('advanceAmount', '2000')->call('save')->assertHasNoErrors()->assertSee('Rs 2,000.00');

        $run = Livewire::test('payroll.run')->set('period', $this->month)->call('generate')->assertSee('Bilal')->assertSee('days needed');
        $payslip = Payslip::sole();

        $run->call('openEdit', $payslip->id)
            ->set('editDays', '20')
            ->assertSee('Rs 18,000.00')
            ->call('addLine', 'earning')->set('editLines.1.label', 'Bonus')->set('editLines.1.amount', '500')
            ->call('addLine', 'deduction')->set('editLines.2.deduction_type_id', (string) $type->id)->set('editLines.2.amount', '1000')
            ->call('saveEdit')->assertHasNoErrors();

        $payslip = $payslip->fresh();
        $this->assertSame(20500.0, $payslip->grossEarnings());
        $this->assertSame(19500.0, (float) $payslip->salary_cost);
        $this->assertSame(17500.0, (float) $payslip->net_pay);

        $run->call('openPay', $payslip->id)->call('pay')->assertHasNoErrors()->assertSee('Paid');

        $this->assertSame(19500.0, (float) Expense::sole()->amount);
        $this->assertSame(0.0, $employee->advanceBalance());

        $run->call('openCancel', $payslip->id)->call('cancelPayslip');
        $this->assertSame(0, Expense::count());
        $this->assertSame(2000.0, $employee->advanceBalance());
    }

    public function test_paying_all_drafts_pays_what_it_can_and_names_what_it_cannot(): void
    {
        $shop = $this->payrollShop();
        $this->employee($shop, ['name' => 'Fixed']);
        $this->employee($shop, ['name' => 'NoDays', 'salary_type' => 'daily', 'rate' => 100]);

        Livewire::test('payroll.run')->set('period', $this->month)->call('generate')->assertSee('Pay All Drafts (2)')
            ->call('openPay')->call('pay')->assertHasErrors('payslip')->assertSee('NoDays has nothing to be paid');

        $this->assertSame(1, Expense::count());
        $this->assertSame(PayslipStatus::Draft, Payslip::where('employee_name', 'NoDays')->sole()->status);
    }

    public function test_deduction_types_are_managed_with_a_tax_note_on_their_screen_and_in_settings(): void
    {
        $this->payrollShop();

        Livewire::test('payroll.deduction-types')
            ->assertSee("This isn't tax advice", false)
            ->set('name', 'Fine')->set('effect', 'reduces')->set('defaultAmount', '50')
            ->call('save')->assertHasNoErrors()->assertSee('Reduces salary cost');

        $type = DeductionType::sole();
        $this->assertTrue($type->reduces_cost);

        Livewire::test('payroll.deduction-types')->set('name', 'Fine')->call('save')->assertHasErrors('name');

        Livewire::test('settings.index')->call('setTab', 'deduction-types')->assertSee('Deduction Types');
    }

    public function test_the_payslip_pdf_and_history_export_render(): void
    {
        $shop = $this->payrollShop(['name' => 'Ali Traders']);
        $employee = $this->employee($shop, ['designation' => 'Helper']);
        $this->give($employee, 5000);
        $paid = $this->pay($this->draftFor($employee));

        $html = view('pdf.payslip', ['payslip' => $paid->fresh(['lines', 'shop'])])->render();
        $this->assertStringContainsString($paid->number(), $html);
        $this->assertStringContainsString($shop->invoiceDisplayName(), $html);
        $this->assertStringContainsString('Advance recovered', $html);
        $this->assertStringContainsString('Rs 25,000.00', $html);
        $this->assertStringStartsWith('%PDF', app(PayslipPdfService::class)->render($paid->fresh()));

        $report = new PayrollReport($this->month, $this->month);
        $rows = $report->tableRows(forExcel: true);
        $this->assertCount(1, $rows);
        $this->assertCount(count($report->tableHeaders()), $rows[0]);
        $this->assertCount(count($report->tableHeaders()), $report->columnTypes());
        $this->assertSame('Rs 30,000.00', collect($report->summaryCards())->firstWhere('label', 'Salary Cost')['value']);
        $this->assertSame('Rs 25,000.00', collect($report->summaryCards())->firstWhere('label', 'Net Pay')['value']);
    }

    public function test_net_summary_code_is_unchanged_it_only_reads_expenses(): void
    {
        $source = file_get_contents(app_path('Services/ShopReportService.php'));

        $this->assertStringContainsString("'netSummary' => \$totalRevenue - \$totalExpenses", $source);
        $this->assertStringNotContainsString('Payslip', $source);
    }
}
