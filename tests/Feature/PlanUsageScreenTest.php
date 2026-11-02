<?php

namespace Tests\Feature;

use App\Enums\FeatureKey;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PlanUsageScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $name, float $price, array $limits, array $features = [], int $sort = 0): Plan
    {
        return Plan::create(['name' => $name, 'price' => $price, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $features + ['whatsapp_ordering' => false, 'whatsapp_messaging' => false], 'sort_order' => $sort])->fresh();
    }

    protected function shopOn(Plan $plan, array $attributes = []): array
    {
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(10)->toDateString()] + $attributes);
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

    protected function pack(string $key, int $quantity, string $billing = 'one_off'): Addon
    {
        return Addon::create(['name' => "Extra {$quantity} {$key}", 'kind' => 'limit_pack', 'limit_key' => $key, 'quantity' => $quantity, 'price' => 1000, 'billing' => $billing])->fresh();
    }

    public function test_the_screen_is_called_plan_and_usage_and_only_owners_get_the_sidebar_link(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', 2000, ['products' => 50]));

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertSee('<title>Plan &amp; Usage', false)->assertDontSee('Plan &amp; Billing', false);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Plan &amp; Usage', false)->assertSee(route('billing.index'));

        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['dashboard', 'settings', 'users']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);

        $this->actingAs($staff)->get(route('dashboard'))->assertOk()->assertDontSee('Plan &amp; Usage', false);
        $this->actingAs($staff)->get(route('billing.index'))->assertForbidden();
    }

    public function test_every_feature_shows_whether_it_is_on_and_where_that_comes_from(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', 2000, ['products' => 50], ['barcode' => true, 'import' => false, 'social' => false]));

        app(Entitlements::class)->setFeatureOverride($shop, FeatureKey::Import, true);

        app(AddonService::class)->purchase($shop->fresh(), Addon::create(['name' => 'Social posting', 'kind' => 'feature_unlock', 'feature_keys' => ['social'], 'price' => 1500, 'billing' => 'monthly'])->fresh(), $owner->id);
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $admin->id);
        $until = ShopAddon::where('shop_id', $shop->id)->firstOrFail()->ends_at;

        $features = collect(app(Entitlements::class)->features($shop->fresh()))->keyBy(fn ($row) => $row['key']->value);

        $this->assertCount(count(FeatureKey::cases()), $features);
        $this->assertSame([true, 'plan'], [$features['barcode']['on'], $features['barcode']['source']]);
        $this->assertSame([false, 'plan'], [$features['whatsapp_ordering']['on'], $features['whatsapp_ordering']['source']]);
        $this->assertSame([false, 'plan'], [$features['whatsapp_messaging']['on'], $features['whatsapp_messaging']['source']]);
        $this->assertSame([true, 'hisabokitab'], [$features['import']['on'], $features['import']['source']]);
        $this->assertSame([true, 'addon'], [$features['social']['on'], $features['social']['source']]);
        $this->assertTrue($until->equalTo($features['social']['until']));

        $this->actingAs($owner)->get(route('billing.index'))
            ->assertSee('What’s included')
            ->assertSee('Included in your plan')
            ->assertSee('Not in your plan')
            ->assertSee('On — set by HisabOKitab')
            ->assertSee('Add-on until '.$until->format('d M Y'));
    }

    public function test_a_shop_without_a_plan_sees_its_features_as_set_by_hisabokitab_and_no_plan_switching(): void
    {
        $shop = Shop::create(['name' => 'Legacy Mobiles', 'subscription_status' => 'active', 'whatsapp_ordering_enabled' => true, 'whatsapp_messaging_enabled' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $this->plan('Starter', 2000, ['products' => 50]);

        $features = collect(app(Entitlements::class)->features($shop->fresh()));
        $this->assertTrue($features->every(fn ($row) => $row['source'] === 'hisabokitab'));

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Contact us to change it')
            ->assertDontSee('Switch to this plan');
    }

    public function test_usage_shows_what_is_left_including_branches_at_one_of_one(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', 2000, ['products' => 10, 'branches' => 1, 'staff_users' => null]));
        $this->products($shop, 4);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('4 / 10')
            ->assertSee('6 left')
            ->assertSee('Branches')
            ->assertSee('1 / 1')
            ->assertSee('0 left')
            ->assertSee('Unlimited on your plan');

        // Elsewhere a single-branch shop still has no Branches meter.
        $this->assertNotContains('branches', array_column(app(Entitlements::class)->meters($shop, fresh: true), 'key'));
    }

    public function test_add_more_buys_the_pack_for_that_limit_and_unlimited_limits_get_no_pack(): void
    {
        [$shop, $owner] = $this->shopOn($this->plan('Starter', 2000, ['products' => 10, 'branches' => 1, 'staff_users' => null]));
        $products = $this->pack('products', 500);
        $branches = $this->pack('branches', 1, 'monthly');
        $staff = $this->pack('staff_users', 2);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Add more')
            ->assertSee('buyAddon('.$products->id.')', false)
            ->assertSee('buyAddon('.$branches->id.')', false)
            ->assertDontSee('buyAddon('.$staff->id.')', false)
            ->assertDontSee($staff->name);

        Volt::actingAs($owner)->test('billing.index')->call('buyAddon', $branches->id)->assertDispatched('toast', type: 'success');

        $this->assertSame($branches->id, ShopAddon::where('shop_id', $shop->id)->sole()->addon_id);
    }

    public function test_plans_are_listed_side_by_side_with_the_current_one_marked(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 10, 'branches' => 1], [], 1);
        $this->plan('Growth', 5000, ['products' => null, 'branches' => 3], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true], 2);
        $retired = $this->plan('Retired', 900, ['products' => 5], [], 3);
        $retired->update(['is_active' => false]);
        [, $owner] = $this->shopOn($starter);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Change plan')
            ->assertSeeInOrder(['Starter', 'Current plan', 'Rs 2,000', 'Growth', 'Rs 5,000', 'Unlimited', 'Switch to this plan'])
            ->assertDontSee('Retired');
    }

    public function test_switching_creates_the_plan_invoice_and_the_payment_card_names_the_plan(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 10], [], 1);
        $growth = $this->plan('Growth', 5000, ['products' => 100], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true], 2);
        [$shop, $owner] = $this->shopOn($starter);

        Volt::actingAs($owner)->test('billing.index')->call('switchPlan', $growth->id)->assertDispatched('toast', type: 'success');

        $invoice = SubscriptionInvoice::where('shop_id', $shop->id)->sole();
        $this->assertSame($growth->id, $invoice->plan_id);
        $this->assertSame($starter->id, $shop->fresh()->plan_id, 'Nothing changes until the payment is confirmed.');

        $this->actingAs($owner)->get(route('billing.index'))
            ->assertSee('For switching to Growth.')
            ->assertSee('Finish your pending payment before changing your plan.');
    }

    public function test_a_blocked_downgrade_shows_what_to_reduce_and_cannot_be_requested(): void
    {
        $growth = $this->plan('Growth', 5000, ['products' => 100], [], 2);
        $small = $this->plan('Small', 1000, ['products' => 3], [], 1);
        [$shop, $owner] = $this->shopOn($growth);
        $this->products($shop, 7);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Small allows fewer than you use now. Before switching, reduce: 4 products.')
            ->assertDontSee('switchPlan('.$small->id.')', false);

        Volt::actingAs($owner)->test('billing.index')->call('switchPlan', $small->id)
            ->assertDispatched('toast', type: 'error', message: 'Small allows fewer than you use now. Before switching, reduce: 4 products.');

        $this->assertSame(0, SubscriptionInvoice::where('shop_id', $shop->id)->count());
    }

    public function test_staff_cannot_switch_plans(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 10]);
        $growth = $this->plan('Growth', 5000, ['products' => 100]);
        [$shop] = $this->shopOn($starter);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id]);

        Volt::actingAs($staff)->test('billing.index')->assertForbidden();

        $this->assertSame(0, SubscriptionInvoice::where('shop_id', $shop->id)->count());
        $this->assertNotNull($growth->id);
    }
}
