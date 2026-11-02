<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAccount;
use App\Models\StockIn;
use App\Models\User;
use App\ReportQueries\StockInReport;
use App\Services\ShopReportService;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BranchUiTest extends TestCase
{
    use RefreshDatabase;

    protected function shop(?array $limits = null): array
    {
        $plan = $limits === null ? null : Plan::create(['name' => 'Plan '.uniqid(), 'price' => 2000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => []]);
        $shop = Shop::create(['name' => 'Shop '.uniqid(), 'plan_id' => $plan?->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        return [$shop, $owner];
    }

    protected function branch(Shop $shop, string $name): Branch
    {
        return Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => $name]);
    }

    protected function staff(Shop $shop, array $permissions, ?Branch $branch = null): User
    {
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Role '.uniqid(), 'permissions' => $permissions]);

        return User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'branch_id' => $branch?->id]);
    }

    protected function stockIn(Shop $shop, Branch $branch, string $name): StockIn
    {
        return StockIn::create(['shop_id' => $shop->id, 'branch_id' => $branch->id, 'product_id' => null, 'product_name' => $name, 'quantity' => 4, 'quantity_remaining' => 4, 'total_cost' => 400, 'payment_status' => 'paid', 'amount_paid' => 400, 'stock_date' => now()->toDateString()]);
    }

    public function test_a_single_branch_shop_shows_no_branch_picker_column_field_or_comparison(): void
    {
        [, $owner] = $this->shop();
        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertOk()->assertDontSee('branchSwitcher')->assertSee(route('branches.index'));
        $this->get(route('users.index'))->assertOk()->assertDontSee('All branches');
        $this->get(route('settings.index', ['tab' => 'shop-accounts']))->assertOk()->assertDontSee('Shared by all branches');
        $this->get(route('reports.index'))->assertOk()->assertDontSee('Branches compared');
    }

    public function test_the_owner_manages_branches_within_the_plan_limit(): void
    {
        [$shop, $owner] = $this->shop(['branches' => 2]);
        $this->actingAs($owner);

        $this->get(route('branches.index'))->assertOk()->assertSee('1 of 2 branches on your plan.');

        Livewire::test('branches.index')->call('openCreate')->set('name', 'City')->set('address', 'Mall Road')->call('save')->assertHasNoErrors();
        $city = Branch::where('name', 'City')->sole();
        $this->assertSame('Mall Road', $city->address);
        $this->assertSame($shop->id, $city->shop_id);

        Livewire::test('branches.index')->call('openCreate')->set('name', 'Saddar')->call('save')->assertDispatched('toast');
        $this->assertFalse(Branch::where('name', 'Saddar')->exists(), 'A third branch is over the plan limit.');

        Livewire::test('branches.index')->call('openCreate')->set('name', 'city')->call('save')->assertHasErrors(['name']);

        $main = $shop->mainBranch;
        Livewire::test('branches.index')->call('archive', $main->id);
        $this->assertNull($main->fresh()->archived_at, 'Main is never archived.');

        Livewire::test('branches.index')->call('archive', $city->id);
        $this->assertNotNull($city->fresh()->archived_at);

        Livewire::test('branches.index')->call('openCreate')->set('name', 'Saddar')->call('save');
        $this->assertTrue(Branch::where('name', 'Saddar')->exists(), 'Archiving freed the slot.');

        Livewire::test('branches.index')->call('reactivate', $city->id);
        $this->assertNotNull($city->fresh()->archived_at, 'Reactivating would go over the limit.');
    }

    public function test_a_branch_with_staff_or_a_cash_drawer_cannot_be_archived(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $staff = $this->staff($shop, ['sales'], $city);
        $this->actingAs($owner);

        Livewire::test('branches.index')->call('archive', $city->id);
        $this->assertNull($city->fresh()->archived_at);

        $staff->update(['branch_id' => null]);
        $drawer = ShopAccount::create(['shop_id' => $shop->id, 'branch_id' => $city->id, 'name' => 'City till', 'provider_type' => 'Cash']);
        Livewire::test('branches.index')->call('archive', $city->id);
        $this->assertNull($city->fresh()->archived_at);

        $drawer->delete();
        Livewire::test('branches.index')->call('archive', $city->id);
        $this->assertNotNull($city->fresh()->archived_at);
    }

    public function test_staff_need_the_branches_permission(): void
    {
        [$shop] = $this->shop();

        $this->actingAs($this->staff($shop, ['sales']))->get(route('branches.index'))->assertForbidden();
        $this->actingAs($this->staff($shop, ['branches']))->get(route('branches.index'))->assertOk();
    }

    public function test_the_switcher_picks_a_branch_and_restricted_staff_cannot_switch(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        [$other] = $this->shop();
        $otherBranchId = $other->mainBranch->id;
        $this->actingAs($owner);

        $this->get(route('dashboard'))->assertOk()->assertSee('branchSwitcher')->assertSee('All branches')->assertSee('City');

        Livewire::test('layout.branch-switcher')->set('branchId', (string) $city->id)->assertRedirect();
        $this->assertSame($city->id, session(BranchContext::SESSION_KEY));
        $this->assertSame($city->id, BranchContext::current()->recordingBranchId());

        Livewire::test('layout.branch-switcher')->set('branchId', (string) $otherBranchId);
        $this->assertNull(session(BranchContext::SESSION_KEY), "Another shop's branch is never selected.");

        $staff = $this->staff($shop, ['sales'], $city);
        $this->actingAs($staff);
        $this->get(route('sales.index'))->assertOk()->assertDontSee('branchSwitcher')->assertSee('City');
        Livewire::test('layout.branch-switcher')->set('branchId', '')->assertForbidden();
    }

    public function test_stock_in_history_follows_the_switcher_and_restricted_staff_cannot_touch_another_branchs_entry(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $this->stockIn($shop, $shop->mainBranch, 'Main cable');
        $mainOnly = $this->stockIn($shop, $shop->mainBranch, 'Main charger');
        $this->stockIn($shop, $city, 'City cable');

        $this->actingAs($owner);
        $this->assertSame(3, (new StockInReport)->query()->count());
        session()->put(BranchContext::SESSION_KEY, $city->id);
        $this->assertSame(['City cable'], (new StockInReport)->query()->pluck('product_name')->all());

        $this->actingAs($this->staff($shop, ['stock-ins', 'stock-ins-edit-cancel'], $city));
        session()->forget(BranchContext::SESSION_KEY);
        $this->assertSame(['City cable'], (new StockInReport)->query()->pluck('product_name')->all());

        $this->expectException(ModelNotFoundException::class);
        StockIn::inCurrentBranch()->findOrFail($mainOnly->id);
    }

    public function test_staff_are_assigned_to_a_branch_and_the_owner_never_is(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);
        $this->actingAs($owner);

        $this->get(route('users.index'))->assertOk()->assertSee('All branches');

        Livewire::test('users.index')->call('openCreate')->set('name', 'Asad')->set('phone', '0300 1234567')->set('roleId', $role->id)->set('branchId', (string) $city->id)->call('save')->assertHasNoErrors();
        $asad = User::where('name', 'Asad')->sole();
        $this->assertSame($city->id, $asad->branch_id);
        $this->assertTrue($asad->isBranchRestricted());

        Livewire::test('users.index')->call('openEdit', $asad->id)->set('branchId', '')->call('save')->assertHasNoErrors();
        $this->assertNull($asad->fresh()->branch_id);

        $archived = $this->branch($shop, 'Closed');
        $archived->update(['archived_at' => now()]);
        Livewire::test('users.index')->call('openEdit', $asad->id)->set('branchId', (string) $archived->id)->call('save')->assertHasErrors(['branchId']);
    }

    public function test_a_cash_drawer_belongs_to_one_branch_and_only_shows_there(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        ShopAccount::create(['shop_id' => $shop->id, 'name' => 'JazzCash', 'provider_type' => 'JazzCash']);
        $this->actingAs($owner);

        Livewire::test('settings.index')->set('shopAccountName', 'City till')->set('shopAccountProviderType', 'Cash')->set('shopAccountBranchId', (string) $city->id)->call('saveShopAccount')->assertHasNoErrors();
        $this->assertSame($city->id, ShopAccount::where('name', 'City till')->sole()->branch_id);

        $names = fn () => Livewire::test('sales.create')->viewData('paymentAccounts')->pluck('name')->all();

        $this->actingAs($this->staff($shop, ['sales'], $city));
        $this->assertSame(['City till', 'JazzCash'], $names());

        $this->actingAs($this->staff($shop, ['sales'], $shop->mainBranch));
        $this->assertSame(['JazzCash'], $names());
    }

    public function test_the_owner_compares_branches_and_the_columns_add_up_to_the_total(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $expense = fn (Branch $branch, int $amount) => Expense::create(['shop_id' => $shop->id, 'branch_id' => $branch->id, 'description' => 'x', 'amount' => $amount, 'expense_date' => now()->toDateString()]);
        $expense($shop->mainBranch, 300);
        $expense($city, 200);
        $expense($city, 50);
        $this->actingAs($owner);

        $comparison = Livewire::test('reports.index')->assertSee('Branches compared')->viewData('branchComparison');
        $byName = collect($comparison['branches'])->mapWithKeys(fn ($row) => [$row['branch']->name => $row['summary']['totalExpenses']]);
        $this->assertSame(['Main' => 300.0, 'City' => 250.0], $byName->all());
        $this->assertSame(550.0, $comparison['total']['totalExpenses']);

        $summary = app(ShopReportService::class)->summary($shop, now()->startOfMonth(), now()->endOfDay());
        $this->assertSame(550.0, $summary['totalExpenses'], 'No branch filter is the whole shop, as before.');
        $this->assertSame(250.0, app(ShopReportService::class)->summary($shop, now()->startOfMonth(), now()->endOfDay(), $city->id)['totalExpenses']);

        session()->put(BranchContext::SESSION_KEY, $city->id);
        Livewire::test('reports.index')->assertViewHas('branchComparison', null);

        $this->actingAs($this->staff($shop, ['reports']));
        session()->forget(BranchContext::SESSION_KEY);
        Livewire::test('reports.index')->assertViewHas('branchComparison', null)->assertDontSee('Branches compared');
    }

    public function test_a_restricted_user_never_widens_past_their_branch_through_a_report_override(): void
    {
        [$shop] = $this->shop();
        $city = $this->branch($shop, 'City');
        Expense::create(['shop_id' => $shop->id, 'branch_id' => $shop->mainBranch->id, 'description' => 'Main', 'amount' => 300, 'expense_date' => now()->toDateString()]);
        Expense::create(['shop_id' => $shop->id, 'branch_id' => $city->id, 'description' => 'City', 'amount' => 200, 'expense_date' => now()->toDateString()]);
        $this->actingAs($this->staff($shop, ['reports'], $city));

        $this->assertSame(200.0, app(ShopReportService::class)->summary($shop, now()->startOfMonth(), now()->endOfDay(), $shop->mainBranch->id)['totalExpenses']);
        $this->assertSame(200.0, BranchContext::current()->forBranch(null, fn () => (float) Expense::sum('amount')));
    }
}
