<?php

namespace Tests\Feature;

use App\Enums\LimitKey;
use App\Enums\SubscriptionStatus;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\Entitlements;
use App\Services\Billing\SubscriptionPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The admin screens on a trial shop (next charge date, badge, no Renew Now),
 * usage meters only for features the shop can use, and module rows that
 * depend on features (Marketing, Integrations).
 */
class PlanAndTrialDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('signup-attempt:127.0.0.1');
        RateLimiter::clear('signup-hour:127.0.0.1');
        RateLimiter::clear('signup-day:127.0.0.1');
        Storage::fake('local');
        Addon::query()->delete();
        Plan::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-18 15:28:00'));
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    /** Like the live "Lite" plan: barcodes, import, services and POs on; WhatsApp, social, store, couriers off; no feature limits. */
    protected function lite(): Plan
    {
        return Plan::create([
            'name' => 'Lite', 'price' => 2500, 'billing_interval' => 'monthly', 'features' => [], 'trial_days' => 15, 'is_active' => true,
            'limits' => ['products' => 100, 'staff_users' => 1, 'sales_per_month' => 1000, 'branches' => 1],
            'feature_flags' => [
                'whatsapp_ordering' => false, 'whatsapp_messaging' => false, 'social' => false, 'ads' => false, 'import' => true,
                'barcode' => true, 'branding' => false, 'services' => true, 'purchase_orders' => true, 'payroll' => false,
                'online_store' => false, 'couriers' => false,
            ],
        ]);
    }

    /** @return array{0: Shop, 1: User} */
    protected function trialShop(?Plan $plan = null): array
    {
        [$shop, $owner] = app(SubscriptionPaymentService::class)->register(
            $plan ?? $this->lite(),
            ['name' => 'Mobility Hub', 'phone' => '+923001234567', 'email' => 'hub@example.pk'],
            ['name' => 'Usama', 'email' => 'hub@example.pk', 'password' => 'secret-pass-1'],
            emailVerified: true,
        );

        return [$shop->fresh(), $owner];
    }

    public function test_a_trial_shop_is_next_charged_when_its_trial_ends_not_a_month_after_signup(): void
    {
        [$shop] = $this->trialShop();
        $cycle = app(BillingCycle::class);

        $this->assertSame('2026-12-18', $shop->expectedRenewalDate()->toDateString(), 'the old calculation, kept for manual shops');
        $this->assertSame('2026-12-03', $cycle->nextChargeDate($shop)->toDateString());
        $this->assertTrue($cycle->onTrial($shop));

        // Past the trial end, still unpaid: the first payment is still what's due.
        $this->travelTo(Carbon::parse('2026-12-05 09:00:00'));
        $this->assertSame('2026-12-03', $cycle->nextChargeDate($shop)->toDateString());
        $this->assertTrue($cycle->onTrial($shop));
    }

    public function test_a_paid_shop_renews_at_the_end_of_its_period_and_a_manual_shop_as_before(): void
    {
        $plan = $this->lite();
        $cycle = app(BillingCycle::class);

        $auto = Shop::create(['name' => 'Paid', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-12-03', 'auto_billing' => true]);
        $this->assertSame('2027-01-03', $cycle->nextChargeDate($auto)->toDateString());
        $this->assertFalse($cycle->onTrial($auto));

        $manual = Shop::create(['name' => 'Manual', 'plan_type' => 'monthly', 'subscription_status' => 'inactive', 'subscription_start_date' => '2026-11-12', 'auto_billing' => false]);
        $this->assertSame('2026-12-12', $cycle->nextChargeDate($manual)->toDateString());
        $this->assertFalse($cycle->onTrial($manual));
    }

    public function test_the_admin_shop_page_shows_the_trial_end_and_no_renew_now_on_a_trial(): void
    {
        [$shop] = $this->trialShop();

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSee('Trial ends / first payment')
            ->assertSeeHtml('data-next-charge')
            ->assertSee('03 Dec 2026')
            ->assertDontSee('18 Dec 2026')
            ->assertDontSee('Expected Renewal')
            ->assertDontSee('Renew Now');
    }

    public function test_renew_now_is_refused_on_a_trial_and_leaves_the_signup_invoice_alone(): void
    {
        [$shop] = $this->trialShop();

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])->call('renewSubscription');

        $shop->refresh();
        $this->assertSame(SubscriptionStatus::Inactive, $shop->subscription_status);
        $this->assertSame('2026-11-18', $shop->subscription_start_date->toDateString());
    }

    public function test_renew_now_still_works_for_a_paid_shop(): void
    {
        $plan = $this->lite();
        $shop = Shop::create(['name' => 'Paid', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-10-01', 'auto_billing' => true]);

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSee('Expected Renewal')
            ->assertSee('Renew Now')
            ->call('renewSubscription');

        $this->assertSame('2026-11-18', $shop->fresh()->subscription_start_date->toDateString());
    }

    public function test_the_shops_list_shows_free_trial_and_the_trial_end(): void
    {
        [$shop] = $this->trialShop();

        $this->actingAs($this->admin())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Mobility Hub', 'Free trial', '18 Nov 2026', '03 Dec 2026'])
            ->assertDontSee('18 Dec 2026');
    }

    public function test_meters_leave_out_limits_whose_feature_the_shop_cannot_use(): void
    {
        [$shop] = $this->trialShop();
        $entitlements = app(Entitlements::class);

        $keys = collect($entitlements->meters($shop, fresh: true, everyLimit: true))->pluck('key')->all();

        $this->assertSame(['products', 'staff_users', 'sales_per_month', 'branches'], $keys);
        $this->assertFalse($entitlements->limitInUse($shop, LimitKey::WhatsAppMessagesPerMonth));
        $this->assertFalse($entitlements->limitInUse($shop, LimitKey::MarketingPostsPerMonth));

        // The features themselves stay off on the shop: the meters only follow them.
        $this->assertFalse((bool) $shop->whatsapp_messaging_enabled);
        $this->assertFalse((bool) $shop->whatsapp_ordering_enabled);
        $this->assertFalse((bool) $shop->online_store_enabled);
        $this->assertFalse((bool) $shop->social_enabled);
    }

    public function test_a_meter_comes_back_when_its_feature_is_switched_on_for_the_shop(): void
    {
        [$shop] = $this->trialShop();
        $entitlements = app(Entitlements::class);

        $entitlements->setFeatureOverride($shop, \App\Enums\FeatureKey::WhatsAppMessaging, true);
        $keys = collect($entitlements->meters($shop->fresh(), fresh: true))->pluck('key')->all();

        $this->assertContains('whatsapp_messages_per_month', $keys);
        // WhatsApp alone opens Marketing, so its posts count too.
        $this->assertContains('marketing_posts_per_month', $keys);
        $this->assertNotContains('whatsapp_orders_per_month', $keys);
        $this->assertNotContains('online_orders_per_month', $keys);
    }

    public function test_admin_and_owner_screens_show_no_unlimited_meter_for_an_off_feature(): void
    {
        [$shop, $owner] = $this->trialShop();

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertViewHas('meters', fn (array $meters) => collect($meters)->pluck('key')->doesntContain('whatsapp_messages_per_month'))
            // The override form keeps every limit, marked when its feature is off.
            ->assertSee('Feature off for this shop (WhatsApp messaging is off), so nothing is counted')
            ->assertSee('Feature off for this shop (needs WhatsApp or Social posting), so nothing is counted')
            ->assertDontSee('0 / Unlimited');

        $this->actingAs($owner->fresh())
            ->get(route('billing.index'))
            ->assertOk()
            ->assertSee('Products')
            ->assertDontSee('WhatsApp messages this month')
            ->assertDontSee('Online orders this month')
            ->assertDontSee('Marketing posts this month');
    }

    public function test_marketing_and_integrations_rows_say_what_they_need_and_cannot_be_switched(): void
    {
        [$shop] = $this->trialShop();

        $this->actingAs($this->admin());

        $page = Livewire::test('admin.shops.show', ['shop' => $shop])
            ->assertSeeHtml('data-module-unavailable="marketing"')
            ->assertSeeHtml('data-module-unavailable="settings-integrations"')
            ->assertSee('Needs WhatsApp or Social posting')
            ->assertDontSeeHtml('data-module-unavailable="dashboard"');

        $page->call('toggleModule', 'marketing');
        $this->assertNotContains('marketing', $shop->fresh()->disabled_screens ?? []);
    }

    public function test_marketing_row_is_a_normal_switch_once_social_posting_is_on(): void
    {
        [$shop] = $this->trialShop();
        app(Entitlements::class)->setFeatureOverride($shop, \App\Enums\FeatureKey::Social, true);

        $this->actingAs($this->admin());

        Livewire::test('admin.shops.show', ['shop' => $shop->fresh()])
            ->assertDontSeeHtml('data-module-unavailable="marketing"')
            ->assertDontSeeHtml('data-module-unavailable="settings-integrations"')
            ->call('toggleModule', 'marketing');

        $this->assertContains('marketing', $shop->fresh()->disabled_screens);
    }
}
