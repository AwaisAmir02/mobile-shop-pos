<?php

namespace Tests\Feature;

use App\Enums\AddonBilling;
use App\Enums\LimitKey;
use App\Exceptions\UsageLimitReached;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\StockIn;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionPaymentService;
use App\Support\BranchBackfill;
use App\Support\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BranchFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(array $limits): Plan
    {
        return Plan::create(['name' => 'Plan '.uniqid(), 'price' => 2000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => []]);
    }

    protected function shop(?Plan $plan = null): array
    {
        $shop = Shop::create(['name' => 'Shop '.uniqid(), 'plan_id' => $plan?->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(5)->toDateString()]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        return [$shop, $owner];
    }

    protected function expense(Shop $shop, string $description, ?Branch $branch = null): Expense
    {
        return Expense::create(['shop_id' => $shop->id, 'branch_id' => $branch?->id, 'description' => $description, 'amount' => 100, 'expense_date' => now()->toDateString()]);
    }

    protected function branch(Shop $shop, string $name): Branch
    {
        return Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => $name]);
    }

    public function test_every_new_shop_gets_exactly_one_main_branch(): void
    {
        [$shop] = $this->shop();

        $branches = Branch::withoutGlobalScopes()->where('shop_id', $shop->id)->get();
        $this->assertCount(1, $branches);
        $this->assertTrue($branches->first()->is_main);
        $this->assertSame('Main', $branches->first()->name);
        $this->assertFalse($shop->hasMultipleBranches());
    }

    public function test_the_backfill_sql_creates_main_branches_and_tags_every_untagged_row(): void
    {
        [$shop, $owner] = $this->shop();
        DB::table('expenses')->insert(['shop_id' => $shop->id, 'user_id' => $owner->id, 'description' => 'Rent', 'amount' => 100, 'expense_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
        Branch::withoutGlobalScopes()->where('shop_id', $shop->id)->delete();

        DB::statement(BranchBackfill::MAIN_BRANCHES_SQL);
        DB::statement(BranchBackfill::MAIN_BRANCHES_SQL);

        foreach (BranchBackfill::TRANSACTION_TABLES as $table) {
            DB::statement(BranchBackfill::tagSql($table));
        }

        $main = Branch::withoutGlobalScopes()->where('shop_id', $shop->id)->sole();
        $this->assertTrue($main->is_main);
        $this->assertSame($main->id, (int) DB::table('expenses')->where('shop_id', $shop->id)->value('branch_id'));
        $this->assertSame([], DB::select(BranchBackfill::reconcileSql()));
    }

    public function test_the_plan_backfill_gives_every_existing_plan_one_branch_and_leaves_set_keys_alone(): void
    {
        $missing = $this->plan(['products' => 10]);
        $none = $this->plan([]);
        DB::table('plans')->where('id', $none->id)->update(['limits' => null]);
        $explicit = $this->plan(['branches' => 3]);
        $unlimited = $this->plan(['branches' => null]);

        DB::statement(BranchBackfill::PLAN_LIMITS_SQL);

        $this->assertEquals(['products' => 10, 'branches' => 1], $missing->fresh()->limits);
        $this->assertSame(['branches' => 1], $none->fresh()->limits);
        $this->assertSame(3, $explicit->fresh()->limits['branches']);
        $this->assertArrayHasKey('branches', $unlimited->fresh()->limits);
        $this->assertNull($unlimited->fresh()->limits['branches']);
    }

    public function test_records_go_to_main_when_nobody_is_logged_in_and_to_the_working_branch_otherwise(): void
    {
        [$shop, $owner] = $this->shop();
        $main = $shop->mainBranch;
        $city = $this->branch($shop, 'City');

        $this->assertSame($main->id, $this->expense($shop, 'Webhook')->branch_id);

        $this->actingAs($owner);
        $this->assertSame($main->id, $this->expense($shop, 'All branches selected')->branch_id);

        session()->put(BranchContext::SESSION_KEY, $city->id);
        $this->assertSame($city->id, $this->expense($shop, 'City selected')->branch_id);

        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'branch_id' => $city->id]);
        session()->forget(BranchContext::SESSION_KEY);
        $this->actingAs($staff);
        $this->assertSame($city->id, $this->expense($shop, 'Restricted staff')->branch_id);
    }

    public function test_branch_scope_limits_restricted_staff_and_the_switcher_but_never_a_single_branch_shop(): void
    {
        [$shop, $owner] = $this->shop();
        $main = $shop->mainBranch;
        $this->expense($shop, 'Main rent', $main);

        $this->actingAs($owner);
        session()->put(BranchContext::SESSION_KEY, $main->id);
        $this->assertSame(1, Expense::count());

        $city = $this->branch($shop, 'City');
        $this->expense($shop, 'City rent', $city);

        $this->assertSame(['Main rent'], Expense::pluck('description')->all());

        session()->forget(BranchContext::SESSION_KEY);
        $this->assertSame(2, Expense::count());

        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'branch_id' => $city->id]);
        $this->actingAs($staff);
        $this->assertSame(['City rent'], Expense::pluck('description')->all());

        $this->assertSame(2, BranchContext::current()->shopWide(fn () => Expense::count()));
        $this->assertSame(1, Expense::count());

        $this->actingAs($owner->fresh());
        $owner->forceFill(['branch_id' => $city->id])->save();
        $this->assertSame(2, Expense::count(), 'The owner is never branch-restricted.');
    }

    public function test_a_selected_branch_that_was_archived_or_belongs_to_another_shop_is_ignored(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $this->branch($shop, 'Mall');
        [$other] = $this->shop();
        $this->expense($shop, 'Main rent', $shop->mainBranch);
        $this->expense($shop, 'City rent', $city);

        $otherShopsBranchId = $other->mainBranch->id;

        $this->actingAs($owner);
        session()->put(BranchContext::SESSION_KEY, $otherShopsBranchId);
        $this->assertSame(2, Expense::count());

        session()->put(BranchContext::SESSION_KEY, $city->id);
        $this->assertSame(1, Expense::count());

        $city->update(['archived_at' => now()]);
        $this->assertSame(2, Expense::count());
    }

    public function test_stock_in_rows_stay_one_shared_fifo_pool_and_only_lists_filter_by_branch(): void
    {
        [$shop, $owner] = $this->shop();
        $city = $this->branch($shop, 'City');
        $product = Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 500, 'stock_quantity' => 10, 'details' => ['category' => 'Cable']]);
        $batch = fn (?Branch $branch) => StockIn::create(['shop_id' => $shop->id, 'branch_id' => $branch?->id, 'product_id' => $product->id, 'product_name' => 'Cable', 'user_id' => $owner->id, 'quantity' => 5, 'cost_price' => 300, 'selling_price' => 500, 'quantity_remaining' => 5, 'total_cost' => 1500, 'payment_status' => 'paid', 'amount_paid' => 1500, 'stock_date' => '2026-01-01']);
        $batch($shop->mainBranch);
        $batch($city);

        $this->actingAs($owner);
        session()->put(BranchContext::SESSION_KEY, $city->id);

        $this->assertSame(2, StockIn::availableBatchesFor($product->id)->count());
        $this->assertSame(2, $product->stockIns()->count());
        $this->assertSame(1, StockIn::inCurrentBranch()->count());
    }

    public function test_branch_limit_blocks_at_the_plan_limit_and_ignores_archived_branches(): void
    {
        [$shop] = $this->shop($this->plan(['branches' => 2]));
        $entitlements = app(Entitlements::class);

        $this->assertSame(1, $entitlements->usage($shop, LimitKey::Branches));
        $entitlements->ensureCanAdd($shop, LimitKey::Branches);
        $mall = $this->branch($shop, 'Mall');

        try {
            $entitlements->ensureCanAdd($shop, LimitKey::Branches);
            $this->fail('A third branch should be blocked on a two-branch plan.');
        } catch (UsageLimitReached) {
        }

        $mall->update(['archived_at' => now()]);
        $this->assertSame(1, $entitlements->usage($shop, LimitKey::Branches));
        $entitlements->ensureCanAdd($shop, LimitKey::Branches);
    }

    public function test_shops_without_a_plan_have_unlimited_branches(): void
    {
        [$shop] = $this->shop();

        $this->assertNull(app(Entitlements::class)->limit($shop, LimitKey::Branches));
        $this->assertTrue(app(Entitlements::class)->allows($shop, LimitKey::Branches, 50));
    }

    public function test_the_branches_meter_only_appears_once_a_shop_has_a_second_branch(): void
    {
        [$shop] = $this->shop($this->plan(['branches' => 1, 'products' => 10]));
        $entitlements = app(Entitlements::class);

        $this->assertNull(collect($entitlements->meters($shop, fresh: true))->firstWhere('key', 'branches'));

        $this->branch($shop, 'City');
        $meter = collect($entitlements->meters($shop, fresh: true))->firstWhere('key', 'branches');
        $this->assertSame(2, $meter['used']);
        $this->assertTrue($meter['over']);
    }

    public function test_a_branch_pack_recurs_and_buying_it_again_renews_without_doubling(): void
    {
        [$shop, $owner] = $this->shop($this->plan(['branches' => 1]));
        $pack = Addon::create(['name' => 'Extra branch', 'kind' => 'limit_pack', 'limit_key' => 'branches', 'quantity' => 1, 'price' => 3000, 'billing' => 'monthly'])->fresh();
        $addons = app(AddonService::class);
        $entitlements = app(Entitlements::class);
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $confirm = fn () => app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $admin->id);

        $addons->purchase($shop, $pack, $owner->id);
        $confirm();
        $first = ShopAddon::sole();
        $this->assertTrue($first->ends_at->isSameDay(now()->addMonthNoOverflow()));
        $this->assertNotSame($shop->expectedRenewalDate()->toDateString(), $first->ends_at->toDateString());
        $this->assertSame(2, $entitlements->limit($shop, LimitKey::Branches));

        $addons->purchase($shop->fresh(), $pack, $owner->id);
        $confirm();
        $second = ShopAddon::latest('id')->first();
        $this->assertTrue($second->starts_at->equalTo($first->ends_at));
        $this->assertTrue($second->ends_at->isSameDay($first->ends_at->copy()->addMonthNoOverflow()));
        $this->assertSame(2, $entitlements->limit($shop, LimitKey::Branches), 'A renewal must not count twice.');

        $this->travelTo($first->ends_at->copy()->addMinute());
        $this->artisan('billing:expire-addons');
        $this->assertSame(2, $entitlements->limit($shop->fresh(), LimitKey::Branches), 'No gap between the two periods.');

        $this->travelTo($second->ends_at->copy()->addMinute());
        $this->artisan('billing:expire-addons');
        $this->assertSame(1, $entitlements->limit($shop->fresh(), LimitKey::Branches));
    }

    public function test_the_admin_saves_branch_packs_as_recurring_and_other_packs_stay_one_off(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'Extra branch')->set('kind', 'limit_pack')->set('limitKey', 'branches')->set('quantity', '1')->set('price', '3000')->set('billing', 'yearly')->call('save')->assertHasNoErrors();
        $this->assertSame(AddonBilling::Yearly, Addon::where('name', 'Extra branch')->sole()->billing);

        Livewire::test('admin.addons')->call('openCreate')->set('name', 'More staff')->set('kind', 'limit_pack')->set('limitKey', 'staff_users')->set('quantity', '2')->set('price', '1000')->set('billing', 'yearly')->call('save')->assertHasNoErrors();
        $this->assertSame(AddonBilling::OneOff, Addon::where('name', 'More staff')->sole()->billing);
    }

    public function test_the_admin_can_override_a_shops_branch_limit(): void
    {
        [$shop] = $this->shop($this->plan(['branches' => 1]));
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));

        Livewire::test('admin.shops.show', ['shop' => $shop])->set('branchesLimit', '4')->call('saveSubscription')->assertHasNoErrors();

        $this->assertSame(4, app(Entitlements::class)->limit($shop->fresh(), LimitKey::Branches));
        $this->assertTrue(Auth::check());
    }
}
