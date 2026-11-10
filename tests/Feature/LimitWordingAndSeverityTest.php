<?php

namespace Tests\Feature;

use App\Enums\LimitKey;
use App\Exceptions\UsageLimitReached;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class LimitWordingAndSeverityTest extends TestCase
{
    use RefreshDatabase;

    // The usage banner's and meter bar's own class strings, so other red or
    // amber elements on the page can't satisfy or break the assertions.
    // x-ui.alert moved to colour tokens, so red is 'danger'
    // and amber is 'warning'. Same severities, new class strings.
    private const RED_BANNER = 'border-danger/20 bg-danger-soft text-ink';

    private const AMBER_BANNER = 'border-warning/20 bg-warning-soft text-ink';

    private const RED_BAR = 'h-full rounded-full bg-red-500';

    private const AMBER_BAR = 'h-full rounded-full bg-amber-500';

    protected function shopOn(array $limits): array
    {
        $plan = Plan::create(['name' => 'Plan '.uniqid(), 'price' => 1000, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => []]);
        $shop = Shop::create(['name' => 'Shop '.uniqid(), 'plan_id' => $plan->id]);

        return [$shop, User::factory()->create(['shop_id' => $shop->id]), $plan];
    }

    public function test_pack_wording_is_singular_for_one_and_plural_otherwise_for_every_limit(): void
    {
        $pack = fn (LimitKey $key, int $quantity) => (new Addon(['kind' => 'limit_pack', 'limit_key' => $key, 'quantity' => $quantity]))->grants();

        $this->assertSame('+1 branch', $pack(LimitKey::Branches, 1));
        $this->assertSame('+2 branches', $pack(LimitKey::Branches, 2));
        $this->assertSame('+1 staff user', $pack(LimitKey::StaffUsers, 1));
        $this->assertSame('+1 product', $pack(LimitKey::Products, 1));
        $this->assertSame('+500 products', $pack(LimitKey::Products, 500));
        $this->assertSame('+1 message a month', $pack(LimitKey::WhatsAppMessagesPerMonth, 1));
        $this->assertSame('+1,000 sales a month', $pack(LimitKey::SalesPerMonth, 1000));
        $this->assertSame('+1 post a month', $pack(LimitKey::MarketingPostsPerMonth, 1));

        $this->assertSame('Your plan allows 1 branch. Upgrade your plan or buy an add-on in Plan & Usage to add more.', (new UsageLimitReached(LimitKey::Branches, 1))->getMessage());
        $this->assertStringStartsWith('Your plan allows 3 branches.', (new UsageLimitReached(LimitKey::Branches, 3))->getMessage());
    }

    public function test_the_plan_editor_needs_at_least_one_branch_but_other_limits_still_take_zero(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true, 'shop_id' => null]));
        $form = fn () => Livewire::test('admin.plans')->call('openCreate')->set('name', 'Basic')->set('price', '1000')->set('features.0.label', 'Everything');

        $form()->set('limits.branches', '0')->call('save')->assertHasErrors(['limits.branches']);
        $form()->set('limits.branches', '1')->set('limits.marketing_posts_per_month', '0')->call('save')->assertHasNoErrors();

        $plan = Plan::where('name', 'Basic')->sole();
        $this->assertSame(1, $plan->limits['branches']);
        $this->assertSame(0, $plan->limits['marketing_posts_per_month']);

        $form()->set('name', 'Open')->call('save')->assertHasNoErrors();
        $this->assertNull(Plan::where('name', 'Open')->sole()->limits['branches'], 'Blank still means unlimited.');
    }

    public function test_filling_branches_or_staff_seats_is_amber_not_red(): void
    {
        [$shop, $owner] = $this->shopOn(['branches' => 2, 'staff_users' => 1, 'products' => 10]);
        Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'City']);
        User::factory()->staff()->create(['shop_id' => $shop->id]);

        $meters = collect(app(Entitlements::class)->meters($shop, fresh: true))->keyBy('key');
        $this->assertSame('full', $meters['branches']['state']);
        $this->assertFalse($meters['branches']['alarm']);
        $this->assertFalse($meters['staff_users']['alarm']);
        $this->assertTrue($meters['products']['alarm']);

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Plan limit reached')->getContent();
        $this->assertStringContainsString(self::AMBER_BANNER, $html);
        $this->assertStringNotContainsString(self::RED_BANNER, $html);

        $billing = $this->actingAs($owner)->get(route('billing.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString(self::RED_BAR, $billing);
        $this->assertStringContainsString(self::AMBER_BAR, $billing);
    }

    public function test_a_full_products_limit_stays_red_even_next_to_full_branches(): void
    {
        [$shop, $owner] = $this->shopOn(['branches' => 2, 'products' => 1]);
        Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'City']);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Cable', 'price' => 100, 'stock_quantity' => 0, 'details' => ['category' => 'Cable']]);

        $html = $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Plan limit reached')->getContent();
        $this->assertStringContainsString(self::RED_BANNER, $html);

        $this->assertStringContainsString(self::RED_BAR, $this->actingAs($owner)->get(route('billing.index'))->getContent());
    }
}
