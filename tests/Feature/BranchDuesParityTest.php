<?php

namespace Tests\Feature;

use App\Enums\ShopScreen;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Repair;
use App\Models\Role;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\ReportQueries\UdhaarReport;
use App\Support\BranchBackfill;
use App\Support\BranchContext;
use App\Support\CustomerDuesStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Balances and dues are shop-wide: whichever branch the viewer works in,
 * restricted or not, every customer and vendor figure is identical. The
 * golden dues fixture is spread across two branches and then read back as
 * five different viewers.
 */
class BranchDuesParityTest extends TestCase
{
    use RefreshDatabase;

    protected function spreadAcrossBranches(Shop $shop): Branch
    {
        $city = Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'City']);

        foreach (BranchBackfill::TRANSACTION_TABLES as $table) {
            DB::table($table)->where('shop_id', $shop->id)->whereRaw('id % 2 = 0')->update(['branch_id' => $city->id]);
        }

        return $city;
    }

    protected function vendorWithStockAtBothBranches(Shop $shop, Branch $main, Branch $city): Vendor
    {
        $vendor = Vendor::create(['shop_id' => $shop->id, 'name' => 'Hafeez Traders']);
        $entry = fn (Branch $branch, string $status, float $paid) => StockIn::create([
            'shop_id' => $shop->id, 'branch_id' => $branch->id, 'vendor_id' => $vendor->id, 'product_id' => null, 'product_name' => 'Cable',
            'quantity' => 10, 'quantity_remaining' => 10, 'total_cost' => 1000, 'payment_status' => $status,
            'amount_paid' => $paid, 'stock_date' => now()->subDays(3)->toDateString(),
        ]);
        $entry($main, 'partial', 300);
        $entry($city, 'unpaid', 0);

        return $vendor;
    }

    protected function staffAt(Shop $shop, ?Branch $branch): User
    {
        $role = Role::firstOrCreate(['shop_id' => $shop->id, 'name' => 'Everything'], ['permissions' => array_column(ShopScreen::cases(), 'value')]);

        return User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id, 'branch_id' => $branch?->id]);
    }

    /** A brand-new request as $user, the way production starts every page load. */
    protected function viewAs(User $user, ?Branch $selected = null): void
    {
        $this->app->forgetScopedInstances();
        $this->actingAs($user->fresh());

        $selected === null ? session()->forget(BranchContext::SESSION_KEY) : session()->put(BranchContext::SESSION_KEY, $selected->id);
    }

    protected function modelFigures(Customer $bilal, Customer $sana, Vendor $vendor): array
    {
        $statement = CustomerDuesStatement::for($bilal->fresh());

        return [
            'bilal udhaar' => $bilal->fresh()->udhaarBalance(),
            'bilal sales outstanding' => $bilal->fresh()->salesOutstandingBalance(),
            'sana udhaar' => $sana->fresh()->udhaarBalance(),
            'sana sales outstanding' => $sana->fresh()->salesOutstandingBalance(),
            'bilal statement total' => $statement->total(),
            'bilal statement lines' => $statement->items()->count(),
            'udhaar balances' => UdhaarTransaction::balancesByCustomer()->sortKeys()->all(),
            'udhaar report total' => (new UdhaarReport)->totalDue(),
            'udhaar report rows' => (new UdhaarReport)->rows()->map(fn ($row) => [$row['customer']->id, $row['balance']])->all(),
            'vendor owed' => $vendor->fresh()->totalOwed(),
        ];
    }

    protected function screenFigures(Customer $bilal, Vendor $vendor): array
    {
        $ledger = Livewire::test('party-ledger.index')->set('customerId', $bilal->id)->viewData('statement');
        $vendorLedger = Livewire::test('party-ledger.index')->set('partyType', 'vendor')->set('vendorId', $vendor->id)->viewData('statement');
        $udhaar = Livewire::test('udhaar.show', ['customer' => $bilal->fresh()]);
        $profile = Livewire::test('customers.show', ['customer' => $bilal->fresh()]);

        return [
            'ledger sales closing' => $ledger['sales']['closing'],
            'ledger udhaar closing' => $ledger['udhaar']['closing'],
            'ledger sales lines' => $ledger['sales']['rows']->count(),
            'ledger udhaar lines' => $ledger['udhaar']['rows']->count(),
            'vendor ledger closing' => $vendorLedger['purchases']['closing'],
            'vendor ledger lines' => $vendorLedger['purchases']['rows']->count(),
            'udhaar screen balance' => $udhaar->viewData('balance'),
            'udhaar screen due lines' => $udhaar->viewData('dues')->count(),
            'profile sales outstanding' => $profile->viewData('salesOutstanding'),
            'profile udhaar' => $profile->viewData('udhaarBalance'),
            'vendor profile owed' => Livewire::test('vendors.show', ['vendor' => $vendor->fresh()])->viewData('totalOwed'),
            'udhaar list' => Livewire::test('udhaar.index')->viewData('rows')->map(fn ($row) => [$row['customer']->id, $row['balance']])->all(),
        ];
    }

    public function test_every_balance_and_due_is_identical_for_the_owner_and_restricted_staff_at_either_branch(): void
    {
        [$shop, $owner, $bilal, $sana] = DuesBaselineTest::seedDuesFixture($this);
        $main = Branch::withoutGlobalScopes()->where('shop_id', $shop->id)->where('is_main', true)->sole();
        $city = $this->spreadAcrossBranches($shop);
        $vendor = $this->vendorWithStockAtBothBranches($shop, $main, $city);
        $cityStaff = $this->staffAt($shop, $city);
        $mainStaff = $this->staffAt($shop, $main);

        $viewers = [
            'owner, all branches' => [$owner, null],
            'owner, City selected' => [$owner, $city],
            'owner, Main selected' => [$owner, $main],
            'staff restricted to City' => [$cityStaff, null],
            'staff restricted to Main' => [$mainStaff, null],
        ];

        $this->viewAs($owner);
        $expected = $this->modelFigures($bilal, $sana, $vendor);
        $this->assertSame(2000.0, $expected['bilal udhaar']);
        $this->assertSame(5000.0, $expected['bilal sales outstanding']);
        $this->assertSame(11350.0, $expected['udhaar report total']);
        $this->assertSame(1700.0, $expected['vendor owed']);

        foreach ($viewers as $label => [$user, $selected]) {
            $this->viewAs($user, $selected);
            $this->assertSame($expected, $this->modelFigures($bilal, $sana, $vendor), "Model-level figures differ for: {$label}");
        }

        $this->viewAs($owner);
        $expectedScreens = $this->screenFigures($bilal, $vendor);
        $this->assertSame(11350.0, $expectedScreens['udhaar screen balance']);
        $this->assertSame(8, $expectedScreens['udhaar screen due lines']);

        foreach ($viewers as $label => [$user, $selected]) {
            $this->viewAs($user, $selected);
            $this->assertSame($expectedScreens, $this->screenFigures($bilal, $vendor), "Screen figures differ for: {$label}");
        }

        $this->viewAs($cityStaff);
        DuesBaselineTest::assertGoldenDues($this, $bilal, $sana);
    }

    public function test_the_parity_is_real_because_ordinary_lists_do_differ_by_branch(): void
    {
        [$shop, $owner] = DuesBaselineTest::seedDuesFixture($this);
        $city = $this->spreadAcrossBranches($shop);
        $cityStaff = $this->staffAt($shop, $city);

        $this->viewAs($owner);
        $all = UdhaarTransaction::count();
        $allRepairs = Repair::count();

        $this->viewAs($cityStaff);
        $this->assertLessThan($all, UdhaarTransaction::count());
        $this->assertGreaterThan(0, UdhaarTransaction::count());
        $this->assertLessThan($allRepairs, Repair::count());

        $this->viewAs($owner, $city);
        $this->assertSame($all, BranchContext::current()->shopWide(fn () => UdhaarTransaction::count()));
        $this->assertLessThan($all, UdhaarTransaction::count());
    }

    public function test_restricted_staff_see_every_branchs_lines_on_a_customer_statement(): void
    {
        [$shop, , $bilal] = DuesBaselineTest::seedDuesFixture($this);
        $city = $this->spreadAcrossBranches($shop);
        $cityStaff = $this->staffAt($shop, $city);
        $branchesOnBilalsLines = DB::table('udhaar_transactions')->where('customer_id', $bilal->id)->distinct()->pluck('branch_id');
        $this->assertCount(2, $branchesOnBilalsLines, 'The fixture should put Bilal\'s udhaar lines at both branches.');

        $this->viewAs($cityStaff);

        Livewire::test('udhaar.show', ['customer' => $bilal->fresh()])
            ->assertViewHas('dues', fn ($dues) => $dues->count() === 8);

        $ledger = Livewire::test('party-ledger.index')->set('customerId', $bilal->id)->viewData('statement');
        $this->assertSame(2, $ledger['udhaar']['rows']->count());
    }
}
