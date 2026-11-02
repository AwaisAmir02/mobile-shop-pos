<?php

namespace Tests\Feature;

use App\Enums\LimitKey;
use App\Enums\PlanType;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\AddonService;
use App\Services\Billing\Entitlements;
use App\Services\Billing\PlanChangeService;
use App\Services\Billing\SubscriptionPaymentService;
use App\Services\SubscriptionBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class PlanChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function plan(string $name, float $price, array $limits, array $features = [], string $interval = 'monthly'): Plan
    {
        return Plan::create(['name' => $name, 'price' => $price, 'billing_interval' => $interval, 'features' => [['label' => 'x', 'included' => true]], 'limits' => $limits, 'feature_flags' => $features + ['whatsapp_ordering' => false, 'whatsapp_messaging' => false]])->fresh();
    }

    protected function shopOn(Plan $plan, array $attributes = []): array
    {
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => $plan->billing_interval->value, 'subscription_status' => 'active', 'subscription_start_date' => now()->subDays(10)->toDateString()] + $attributes);
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

    protected function confirmLatest(): SubscriptionPayment
    {
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);

        return app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::latest('id')->firstOrFail(), $admin->id);
    }

    protected function changes(): PlanChangeService
    {
        return app(PlanChangeService::class);
    }

    public function test_asking_to_switch_creates_a_plan_invoice_and_changes_nothing_until_it_is_paid(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 5]);
        $growth = $this->plan('Growth', 5000, ['products' => 50], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true], 'yearly');
        [$shop, $owner] = $this->shopOn($starter);

        $payment = $this->changes()->request($shop, $growth, $owner->id);

        $invoice = $payment->invoice;
        $this->assertSame($growth->id, $invoice->plan_id);
        $this->assertSame(5000.0, (float) $invoice->total);
        $this->assertSame(12, $invoice->months, 'A yearly plan is billed for 12 months.');
        $this->assertSame('yearly', $invoice->plan_type, 'The invoice carries the new plan’s interval, not the shop’s current one.');
        $this->assertSame('Plan change: Starter → Growth', $invoice->notes);
        $this->assertSame(SubscriptionPaymentStatus::Pending, $payment->status);

        $shop->refresh();
        $this->assertSame($starter->id, $shop->plan_id);
        $this->assertSame(PlanType::Monthly, $shop->plan_type);
        $this->assertFalse($shop->usesWhatsApp());
        $this->assertSame(5, app(Entitlements::class)->limit($shop, LimitKey::Products));
    }

    public function test_confirming_the_payment_moves_the_shop_onto_the_plan_from_the_confirmation_day(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 5]);
        $growth = $this->plan('Growth', 5000, ['products' => 50], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true], 'yearly');
        [$shop, $owner] = $this->shopOn($starter);
        app(Entitlements::class)->meters($shop);

        $this->changes()->request($shop, $growth, $owner->id);
        $this->travel(3)->days();
        $this->confirmLatest();

        $shop->refresh();
        $this->assertSame($growth->id, $shop->plan_id);
        $this->assertSame(PlanType::Yearly, $shop->plan_type);
        $this->assertSame(SubscriptionStatus::Active, $shop->subscription_status);
        $this->assertSame(now()->toDateString(), $shop->subscription_start_date->toDateString());
        $this->assertSame(now()->addYear()->toDateString(), $shop->expectedRenewalDate()->toDateString());
        $this->assertTrue($shop->whatsapp_ordering_enabled && $shop->whatsapp_messaging_enabled, 'The new plan’s features switch on.');
        $this->assertSame(50, collect(app(Entitlements::class)->meters($shop))->firstWhere('key', 'products')['limit'], 'Meters are refreshed, not served from cache.');

        $invoice = SubscriptionInvoice::where('plan_id', $growth->id)->sole();
        $this->assertTrue($invoice->isPaid());
        $this->assertSame(now()->toDateString(), $invoice->period_start->toDateString(), 'The paid period starts on the confirmation day.');
        $this->assertSame(SubscriptionInvoice::periodEndFor(now()->startOfDay(), 12)->toDateString(), $invoice->period_end->toDateString());
        $this->assertSame(5000.0, (float) $invoice->total, 'Re-dating never changes the amount.');
    }

    public function test_a_downgrade_is_blocked_while_any_hard_limit_is_over_and_says_what_to_reduce(): void
    {
        $growth = $this->plan('Growth', 5000, ['products' => 50, 'branches' => 3, 'staff_users' => 5]);
        $starter = $this->plan('Starter', 2000, ['products' => 3, 'branches' => 1, 'staff_users' => 1]);
        [$shop, $owner] = $this->shopOn($growth);
        $this->products($shop, 5);
        Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'City']);
        Branch::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'name' => 'Mall']);
        User::factory()->staff()->count(2)->create(['shop_id' => $shop->id]);

        $blockers = collect($this->changes()->blockers($shop, $starter))->keyBy(fn ($b) => $b['key']->value);
        $this->assertSame(['products', 'staff_users', 'branches'], $blockers->keys()->all());
        $this->assertSame(2, $blockers['products']['reduceBy']);
        $this->assertSame(2, $blockers['branches']['reduceBy']);
        $this->assertSame(1, $blockers['staff_users']['reduceBy']);

        try {
            $this->changes()->request($shop, $starter, $owner->id);
            $this->fail('The downgrade should be blocked.');
        } catch (RuntimeException $e) {
            $this->assertSame('Starter allows fewer than you use now. Before switching, reduce: 2 products, 1 staff user, 2 branches.', $e->getMessage());
        }

        $this->assertSame(0, SubscriptionInvoice::where('plan_id', $starter->id)->count());
        $this->assertNull(SubscriptionPayment::openFor($shop->id));
    }

    public function test_a_downgrade_goes_through_once_usage_fits_exactly(): void
    {
        $growth = $this->plan('Growth', 5000, ['products' => 50]);
        $starter = $this->plan('Starter', 2000, ['products' => 3]);
        [$shop, $owner] = $this->shopOn($growth);
        $this->products($shop, 3);

        $this->assertSame([], $this->changes()->blockers($shop, $starter));
        $this->changes()->request($shop, $starter, $owner->id);
        $this->confirmLatest();

        $this->assertSame($starter->id, $shop->fresh()->plan_id);
    }

    public function test_the_downgrade_check_counts_the_shops_own_override_and_add_on_packs(): void
    {
        $growth = $this->plan('Growth', 5000, ['products' => 50]);
        $starter = $this->plan('Starter', 2000, ['products' => 3]);
        [$shop] = $this->shopOn($growth, ['limit_overrides' => ['products' => 10]]);
        $this->products($shop, 8);

        $this->assertSame([], $this->changes()->blockers($shop, $starter), 'The shop’s own override still applies on the new plan.');

        $shop->update(['limit_overrides' => null]);
        $this->assertCount(1, $this->changes()->blockers($shop->fresh(), $starter));

        $addon = Addon::create(['name' => 'Extra', 'kind' => 'limit_pack', 'limit_key' => 'products', 'quantity' => 5, 'price' => 100, 'billing' => 'one_off']);
        ShopAddon::create(['shop_id' => $shop->id, 'addon_id' => $addon->id, 'status' => 'active', 'name' => 'Extra', 'kind' => 'limit_pack', 'limit_key' => 'products', 'quantity' => 5, 'price' => 100, 'billing' => 'one_off', 'starts_at' => now(), 'ends_at' => now()->addMonth()]);
        $this->assertSame([], $this->changes()->blockers($shop->fresh(), $starter), 'Packs in effect count on the new plan too.');
    }

    public function test_monthly_hard_limits_block_but_the_sales_limit_never_does(): void
    {
        $growth = $this->plan('Growth', 5000, ['marketing_posts_per_month' => 20, 'sales_per_month' => 1000]);
        $starter = $this->plan('Starter', 2000, ['marketing_posts_per_month' => 0, 'sales_per_month' => 0]);
        [$shop] = $this->shopOn($growth);
        \App\Models\MarketingPost::withoutGlobalScopes()->create(['shop_id' => $shop->id, 'kind' => 'product', 'ref_code' => 'P1', 'caption' => 'x', 'status' => 'draft']);
        \App\Models\Sale::withoutGlobalScopes()->forceCreate(['shop_id' => $shop->id, 'subtotal' => 100, 'discount_amount' => 0, 'total' => 100, 'status' => 'completed']);

        $blockers = collect($this->changes()->blockers($shop, $starter))->map(fn ($b) => $b['key']->value)->all();
        $this->assertSame(['marketing_posts_per_month'], $blockers);
        $this->assertStringContainsString('1 post this month', $this->changes()->blockerMessage($shop, $starter));
    }

    public function test_switching_is_refused_for_the_current_plan_an_inactive_plan_an_open_payment_or_a_shop_without_a_plan(): void
    {
        $starter = $this->plan('Starter', 2000, []);
        $growth = $this->plan('Growth', 5000, []);
        $retired = $this->plan('Retired', 1000, []);
        $retired->update(['is_active' => false]);
        [$shop, $owner] = $this->shopOn($starter);

        $refused = function (Shop $shop, Plan $plan, string $message) use ($owner) {
            try {
                $this->changes()->request($shop, $plan, $owner->id);
                $this->fail("Expected: {$message}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        };

        $refused($shop, $starter, 'already on Starter');
        $refused($shop, $retired, 'no longer available');

        $this->changes()->request($shop, $growth, $owner->id);
        $refused($shop->fresh(), $this->plan('Pro', 9000, []), 'finish your pending payment');

        $legacy = Shop::create(['name' => 'Legacy']);
        $refused($legacy, $growth, 'set up by HisabOKitab');

        $this->assertSame(1, SubscriptionInvoice::whereNotNull('plan_id')->count());
    }

    public function test_a_rejected_plan_payment_leaves_the_shop_on_its_plan(): void
    {
        $starter = $this->plan('Starter', 2000, []);
        $growth = $this->plan('Growth', 5000, [], ['whatsapp_ordering' => true, 'whatsapp_messaging' => true]);
        [$shop, $owner] = $this->shopOn($starter);

        $payment = $this->changes()->request($shop, $growth, $owner->id);
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        app(SubscriptionPaymentService::class)->reject($payment, $admin->id, 'Reference not found');

        $shop->refresh();
        $this->assertSame($starter->id, $shop->plan_id);
        $this->assertFalse($shop->usesWhatsApp());
    }

    public function test_the_switch_still_happens_if_usage_grew_while_the_payment_waited(): void
    {
        $growth = $this->plan('Growth', 5000, ['products' => 50]);
        $starter = $this->plan('Starter', 2000, ['products' => 3]);
        [$shop, $owner] = $this->shopOn($growth);
        $this->products($shop, 3);

        $this->changes()->request($shop, $starter, $owner->id);
        Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => 'Added later', 'price' => 100, 'stock_quantity' => 1, 'details' => ['category' => 'Cable']]);
        $this->confirmLatest();

        $shop->refresh();
        $this->assertSame($starter->id, $shop->plan_id, 'The owner paid, so the plan changes.');
        $this->assertTrue(collect(app(Entitlements::class)->meters($shop, fresh: true))->firstWhere('key', 'products')['over']);
    }

    public function test_renewal_signup_and_add_on_invoices_confirm_exactly_as_before(): void
    {
        $starter = $this->plan('Starter', 2000, ['products' => 5]);
        [$shop, $owner] = $this->shopOn($starter, ['subscription_status' => 'inactive']);

        $renewal = app(SubscriptionBillingService::class)->create($shop, ['period_start' => now()->subDays(2)->toDateString(), 'months' => 1, 'amount' => 2000], $owner->id);
        $this->assertNull($renewal->plan_id);
        app(\App\Services\Billing\PaymentGateways::class)->current()->start($renewal);
        $this->confirmLatest();

        $shop->refresh();
        $this->assertSame($starter->id, $shop->plan_id);
        $this->assertSame(SubscriptionStatus::Active, $shop->subscription_status);
        $this->assertSame(now()->subDays(2)->toDateString(), $shop->subscription_start_date->toDateString(), 'A renewal still starts on its invoice date.');
        $this->assertSame(now()->subDays(2)->toDateString(), $renewal->fresh()->period_start->toDateString());

        $pack = Addon::create(['name' => 'More', 'kind' => 'limit_pack', 'limit_key' => 'products', 'quantity' => 10, 'price' => 500, 'billing' => 'one_off'])->fresh();
        app(AddonService::class)->purchase($shop->fresh(), $pack, $owner->id);
        $this->confirmLatest();

        $this->assertSame($starter->id, $shop->fresh()->plan_id);
        $this->assertSame(15, app(Entitlements::class)->limit($shop->fresh(), LimitKey::Products));
    }
}
