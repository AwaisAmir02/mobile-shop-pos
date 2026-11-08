<?php

namespace Tests\Feature;

use App\Enums\LimitKey;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\LimitNotices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LimitNoticeTest extends TestCase
{
    use RefreshDatabase;

    // Exact box classes of <x-ui.alert> per variant.
    // Design overhaul D1: x-ui.alert moved to colour tokens, so red is 'danger'
    // and amber is 'warning'. Same severities, new class strings.
    private const RED = 'border-danger/20 bg-danger-soft text-ink';

    private const AMBER = 'border-warning/20 bg-warning-soft text-ink';

    protected function setUp(): void
    {
        parent::setUp();

        // Migrations seed default plans and an "Extra 500 products" pack;
        // each test builds exactly the catalogue it needs.
        Addon::query()->delete();
        Plan::query()->delete();
    }

    protected function plan(string $name, array $limits, array $features = [], int $sort = 0): Plan
    {
        return Plan::create(['name' => $name, 'price' => 2000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $features + ['whatsapp_ordering' => false, 'whatsapp_messaging' => false], 'sort_order' => $sort])->fresh();
    }

    protected function shopOn(Plan $plan): array
    {
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(10)->toDateString()]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        app(Entitlements::class)->syncFeatureFlags($shop);

        return [$shop->fresh(), $owner];
    }

    protected function products(Shop $shop, int $count): void
    {
        foreach (range(1, $count) as $i) {
            Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => "Item {$i}", 'price' => 100, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);
        }
    }

    protected function pack(string $key): Addon
    {
        return Addon::create(['name' => "More {$key}", 'kind' => 'limit_pack', 'limit_key' => $key, 'quantity' => 5, 'price' => 1000, 'billing' => 'one_off'])->fresh();
    }

    protected function staff(Shop $shop, array $permissions): User
    {
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Clerk', 'permissions' => $permissions]);

        return User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
    }

    public function test_nothing_shows_while_comfortably_under_the_limit_or_without_a_plan(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 10]));
        $this->products($shop, 3);

        $this->actingAs($owner)->get(route('products.index'))->assertOk()->assertDontSee('data-limit-notice', false);

        $legacy = Shop::create(['name' => 'Legacy Mobiles', 'subscription_status' => 'active']);
        $this->assertNull(app(LimitNotices::class)->for($legacy, LimitKey::Products));
    }

    public function test_nearing_the_limit_shows_an_amber_notice_with_no_buttons_when_there_is_nothing_to_buy(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 5]));
        $this->products($shop, 4);

        $html = $this->actingAs($owner)->get(route('products.index'))->assertOk()
            ->assertSee('data-limit-notice="products"', false)
            ->assertSee('Nearing your plan limit')
            ->assertSee('You’ve used 4 of 5 products.')
            ->assertDontSee('Buy add-on')
            ->assertDontSee('Upgrade plan')
            ->getContent();

        $this->assertStringContainsString(self::AMBER, $html);
    }

    public function test_a_full_limit_is_red_and_the_owner_gets_add_on_and_upgrade_links_into_plan_and_usage(): void
    {
        $starter = $this->plan('Starter', ['products' => 5], [], 1);
        $this->plan('Growth', ['products' => 50], [], 2);
        [$shop, $owner] = $this->shopOn($starter);
        $this->products($shop, 5);
        $this->pack('products');

        $html = $this->actingAs($owner)->get(route('products.index'))->assertOk()
            ->assertSee('Plan limit reached')
            ->assertSee('You’ve used all 5 products on your plan. You can’t add more until you add to your plan.')
            ->assertSee('Buy add-on')
            ->assertSee(route('billing.index').'#addons', false)
            ->assertSee('Upgrade plan')
            ->assertSee(route('billing.index').'#plans', false)
            ->getContent();

        $this->assertStringContainsString(self::RED, $html);

        // The anchors exist on Plan & Usage.
        $this->actingAs($owner)->get(route('billing.index'))->assertSee('id="addons"', false)->assertSee('id="plans"', false);
    }

    public function test_upgrade_only_counts_active_plans_that_actually_raise_the_limit(): void
    {
        $starter = $this->plan('Starter', ['products' => 5], [], 1);
        $smaller = $this->plan('Mini', ['products' => 3], [], 0);
        $retired = $this->plan('Old Pro', ['products' => null], [], 3);
        $retired->update(['is_active' => false]);
        [$shop] = $this->shopOn($starter);
        $this->products($shop, 5);

        $this->assertFalse(app(LimitNotices::class)->for($shop, LimitKey::Products)['upgrade']);

        $this->plan('Unlimited', ['products' => null], [], 4);
        $this->assertTrue(app(LimitNotices::class)->for($shop, LimitKey::Products)['upgrade']);
        $this->assertNotNull($smaller->id);
    }

    public function test_going_over_says_by_how_much(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 5]));
        $this->products($shop, 7);

        $this->actingAs($owner)->get(route('products.index'))->assertOk()
            ->assertSee('You’re using 7 products — your plan allows 5.');
    }

    public function test_staff_are_told_to_ask_the_owner_and_get_no_billing_links(): void
    {
        $starter = $this->plan('Starter', ['products' => 5], [], 1);
        $this->plan('Growth', ['products' => 50], [], 2);
        [$shop] = $this->shopOn($starter);
        $this->products($shop, 5);
        $this->pack('products');

        $this->actingAs($this->staff($shop, ['products']))->get(route('products.index'))->assertOk()
            ->assertSee('Plan limit reached')
            ->assertSee('Ask the shop owner to upgrade.')
            ->assertDontSee('Buy add-on')
            ->assertDontSee('Upgrade plan')
            ->assertDontSee(route('billing.index'));
    }

    public function test_full_staff_seats_and_branches_are_amber_on_their_own_screens(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 50, 'staff_users' => 1, 'branches' => 1]));
        User::factory()->staff()->create(['shop_id' => $shop->id]);
        $this->pack('branches');

        $team = $this->actingAs($owner)->get(route('users.index'))->assertOk()->assertSee('data-limit-notice="staff_users"', false)->getContent();
        $this->assertStringContainsString(self::AMBER, $team);
        $this->assertStringNotContainsString(self::RED, $team);

        // A single-branch shop at 1/1 still gets the notice where it adds branches.
        $branches = $this->actingAs($owner)->get(route('branches.index'))->assertOk()
            ->assertSee('data-limit-notice="branches"', false)
            ->assertSee('You’ve used all 1 branch on your plan.')
            ->assertSee('Buy add-on')
            ->getContent();
        $this->assertStringContainsString(self::AMBER, $branches);
    }

    public function test_sales_get_an_informational_notice_with_no_actions(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 50, 'sales_per_month' => 0], [], 1));
        $this->plan('Growth', ['sales_per_month' => null], [], 2);
        $this->pack('sales_per_month');

        $this->actingAs($owner)->get(route('sales.index'))->assertOk()
            ->assertSee('data-limit-notice="sales_per_month"', false)
            ->assertSee('Sales keep working — the plan just needs upgrading.')
            ->assertDontSee('Buy add-on')
            ->assertDontSee('Upgrade plan');

        $this->actingAs($this->staff($shop, ['sales']))->get(route('sales.index'))->assertOk()
            ->assertSee('Sales keep working')
            ->assertDontSee('Ask the shop owner to upgrade.');
    }

    public function test_marketing_posts_and_customer_messages_show_their_monthly_limits(): void
    {
        [, $owner] = $this->shopOn($this->plan('Starter', ['marketing_posts_per_month' => 0, 'whatsapp_messages_per_month' => 0], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true]));

        $this->actingAs($owner)->get(route('marketing.posts'))->assertOk()
            ->assertSee('data-limit-notice="marketing_posts_per_month"', false)
            ->assertSee('You’ve used all 0 posts this month on your plan. You can’t add more until you add to your plan or the count resets on the 1st.');

        $this->actingAs($owner)->get(route('whatsapp-messages.index'))->assertOk()
            ->assertSee('data-limit-notice="whatsapp_messages_per_month"', false);
    }

    public function test_the_banner_leaves_out_only_the_limit_this_screen_already_shows(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', ['products' => 5, 'staff_users' => 1]));
        $this->products($shop, 5);
        User::factory()->staff()->create(['shop_id' => $shop->id]);

        // Elsewhere the banner names both.
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Products (5/5)')->assertSee('Staff users (1/1)');

        // On Products, Products is explained by the notice; the banner still mentions staff.
        $this->actingAs($owner)->get(route('products.index'))->assertOk()
            ->assertSee('data-limit-notice="products"', false)
            ->assertDontSee('Products (5/5)')
            ->assertSee('Staff users (1/1)');

        // With nothing else to say, the banner disappears entirely on Team.
        Product::where('shop_id', $shop->id)->limit(3)->get()->each->delete();
        app(Entitlements::class)->forgetMeters($shop);

        $this->actingAs($owner)->get(route('users.index'))->assertOk()
            ->assertSee('data-limit-notice="staff_users"', false)
            ->assertDontSee('Staff users (1/1)')
            ->assertDontSee('Plan limit reached:');
    }
}
