<?php

namespace Tests\Feature;

use App\Enums\BillingPhase;
use App\Enums\ShopAddonStatus;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Mail\VerificationCodeMail;
use App\Models\Addon;
use App\Models\PaymentAccount;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Shop;
use App\Models\ShopAddon;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\SubscriptionPaymentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SubscriptionTrialAndRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('signup-attempt:127.0.0.1');
        RateLimiter::clear('signup-hour:127.0.0.1');
        RateLimiter::clear('signup-day:127.0.0.1');
        Storage::fake('local');
        // Migrations seed a catalogue; these tests use their own.
        Addon::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    protected function plan(array $attributes = []): Plan
    {
        return Plan::create($attributes + ['name' => 'Pro', 'price' => 3000, 'billing_interval' => 'monthly', 'features' => [], 'limits' => [], 'feature_flags' => ['ads' => false], 'trial_days' => 15, 'is_active' => true]);
    }

    /** A self-serve signup on $plan, as the signup screens make it. @return array{0: Shop, 1: User} */
    protected function trialShop(?Plan $plan = null): array
    {
        [$shop, $owner] = app(SubscriptionPaymentService::class)->register(
            $plan ?? $this->plan(),
            ['name' => 'Ali Mobiles', 'phone' => '+923001234567', 'email' => 'ali@example.pk'],
            ['name' => 'Ali Raza', 'email' => 'ali@example.pk', 'password' => 'secret-pass-1'],
            emailVerified: true,
        );

        return [$shop->fresh(), $owner];
    }

    /** An automatic-billing shop that has paid for the month starting $start. @return array{0: Shop, 1: User} */
    protected function paidShop(string $start = '2026-11-01', ?Plan $plan = null): array
    {
        $plan ??= $this->plan();
        $shop = Shop::create(['name' => 'Ali Mobiles', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => $start, 'auto_billing' => true]);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        return [$shop, $owner];
    }

    protected function unlock(string $billing = 'monthly', int $price = 1500): Addon
    {
        return Addon::create(['name' => 'Ads', 'kind' => 'feature_unlock', 'feature_keys' => ['ads'], 'price' => $price, 'billing' => $billing])->fresh();
    }

    protected function pack(): Addon
    {
        return Addon::create(['name' => 'Extra 100 products', 'kind' => 'limit_pack', 'limit_key' => 'products', 'quantity' => 100, 'price' => 1000, 'billing' => 'one_off'])->fresh();
    }

    protected function sendProof(User $owner): void
    {
        Livewire::actingAs($owner)->test('billing.index')->set('reference', 'TID-5555')->set('proof', UploadedFile::fake()->image('slip.jpg'))->call('submitProof')->assertHasNoErrors();
    }

    protected function confirmOpenPayment(Shop $shop): void
    {
        app(SubscriptionPaymentService::class)->confirm(SubscriptionPayment::where('shop_id', $shop->id)->open()->latest('id')->firstOrFail(), $this->admin()->id);
    }

    protected function cycle(): BillingCycle
    {
        return app(BillingCycle::class);
    }

    public function test_signup_starts_the_plans_trial_with_full_access_and_lands_on_the_dashboard(): void
    {
        $plan = Plan::where('name', 'Growth')->firstOrFail();
        $this->assertSame(15, $plan->trial_days);

        $component = Livewire::test('signup.form', ['plan' => (string) $plan->id]);
        $this->travel(5)->seconds();
        Mail::fake();
        foreach (['shopName' => 'Ali Mobiles', 'businessCategory' => 'mobile_electronics', 'ownerName' => 'Ali Raza', 'phone' => '0300 1234567', 'email' => 'ali@example.pk', 'password' => 'secret-pass-1', 'password_confirmation' => 'secret-pass-1'] as $key => $value) {
            $component->set($key, $value);
        }
        $component->call('submit');
        $component->set('code', Mail::sent(VerificationCodeMail::class)->last()->code)->call('verify')->assertHasNoErrors()->assertRedirect(route('dashboard'));

        $shop = Shop::sole();
        $this->assertTrue($shop->auto_billing);
        $this->assertSame(SubscriptionStatus::Inactive, $shop->subscription_status);
        $this->assertSame(now()->addDays(15)->toDateTimeString(), $shop->trial_ends_at->toDateTimeString());
        $this->assertSame(BillingPhase::Trial, $this->cycle()->phase($shop));

        $invoice = SubscriptionInvoice::sole();
        $this->assertSame('2026-11-26', $invoice->period_start->toDateString());
        $this->assertSame('2026-12-25', $invoice->period_end->toDateString());
        $this->assertSame('4500.00', $invoice->total);
        $this->assertSame([['kind' => 'plan', 'label' => 'Growth plan, 26 Nov – 25 Dec 2026', 'amount' => 4500]], $invoice->items);

        $owner = User::sole();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Free trial')->assertSee('15 days left')->assertSee('Pay now')->assertDontSee('Payment pending.');
        $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertSee('up to a day')->assertSee('Growth plan, 26 Nov – 25 Dec 2026');
    }

    public function test_a_plan_with_no_trial_asks_for_payment_straight_away(): void
    {
        [$shop, $owner] = $this->trialShop($this->plan(['trial_days' => 0]));

        $this->assertSame(BillingPhase::TrialEnded, $this->cycle()->phase($shop));
        $this->assertSame(now()->toDateString(), SubscriptionInvoice::sole()->period_start->toDateString());
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.index'));
    }

    public function test_the_plan_editor_sets_the_trial_length(): void
    {
        $this->actingAs($this->admin());
        $plan = $this->plan();

        Livewire::test('admin.plans')->call('openEdit', $plan->id)->assertSet('trialDays', '15')->set('trialDays', '400')->call('save')->assertHasErrors(['trialDays']);
        Livewire::test('admin.plans')->call('openEdit', $plan->id)->set('trialDays', '30')->call('save')->assertHasNoErrors();
        $this->assertSame(30, $plan->fresh()->trial_days);

        Livewire::test('admin.plans')->call('openCreate')->assertSet('trialDays', '15')->set('name', 'Lite')->set('price', '1000')->set('trialDays', '0')->call('save')->assertHasNoErrors();
        $this->assertSame(0, Plan::where('name', 'Lite')->sole()->trial_days);
    }

    public function test_when_the_trial_ends_unpaid_every_screen_sends_the_owner_to_pay(): void
    {
        [$shop, $owner] = $this->trialShop();
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales', 'products']])->id]);

        $this->travelTo($shop->trial_ends_at->copy()->subMinute());
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('1 day left');

        $this->travelTo($shop->trial_ends_at->copy()->addMinute());
        $this->assertTrue($this->cycle()->locked($shop));

        foreach (['dashboard', 'products.index', 'sales.index', 'customers.index', 'expenses.index', 'settings.index', 'reports.index'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertRedirect(route('billing.index'));
        }

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Your free trial has ended')->assertSee('Every screen is paused')->assertSee('up to a day')->assertSee('Rs 3,000.00');
        $this->actingAs($owner)->get(route('support.index'))->assertOk();
        $this->actingAs($owner)->get(route('profile'))->assertOk();
        $this->actingAs($owner)->get(route('billing.invoices.pdf', SubscriptionInvoice::sole()))->assertOk();

        $this->actingAs($staff)->get(route('sales.index'))->assertRedirect(route('billing.locked'));
        $this->actingAs($staff)->get(route('billing.locked'))->assertOk()->assertSee('free trial has ended')->assertSee('Ali Raza');

        $this->actingAs($this->admin())->get(route('admin.shops.show', $shop))->assertOk()->assertSee('Trial ended — unpaid');
    }

    public function test_actions_on_a_page_already_open_stop_when_the_trial_ends(): void
    {
        [$shop, $owner] = $this->trialShop();
        $this->actingAs($owner);

        $html = $this->get(route('expenses.index'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches);
        $snapshot = collect($matches[1])->map(fn ($raw) => html_entity_decode($raw))
            ->first(fn ($json) => (json_decode($json, true)['memo']['name'] ?? null) === 'expenses.index');
        $payload = ['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]];
        $this->postJson('/livewire/update', $payload)->assertOk();

        $this->travelTo($shop->trial_ends_at->copy()->addMinute());

        $this->postJson('/livewire/update', $payload)->assertRedirect(route('billing.index'));
    }

    public function test_a_payment_sent_for_review_keeps_the_shop_open_until_it_is_checked(): void
    {
        [$shop, $owner] = $this->trialShop();
        $this->travelTo($shop->trial_ends_at->copy()->addDay());
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.index'));

        $this->sendProof($owner);

        $this->assertFalse($this->cycle()->locked($shop));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Payment received');

        Livewire::actingAs($this->admin())->test('admin.payments')->call('openReject', SubscriptionPayment::sole()->id)->set('rejectReason', 'No transfer found')->call('reject');

        $this->assertTrue($this->cycle()->locked($shop));
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.index'));
        $this->actingAs($owner)->get(route('billing.index'))->assertSee('No transfer found');
    }

    public function test_paying_during_the_trial_starts_the_first_period_when_the_trial_ends(): void
    {
        [$shop, $owner] = $this->trialShop();
        $this->travel(4)->days();

        $this->sendProof($owner);
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Payment received');
        $this->confirmOpenPayment($shop);

        $shop->refresh();
        $this->assertSame(SubscriptionStatus::Active, $shop->subscription_status);
        $this->assertSame('2026-11-26', $shop->subscription_start_date->toDateString());
        $this->assertSame('2026-12-26', $shop->expectedRenewalDate()->toDateString());
        $this->assertSame('2026-11-26', SubscriptionInvoice::sole()->period_start->toDateString());
        $this->assertSame(BillingPhase::Active, $this->cycle()->phase($shop));

        $owner = $owner->fresh();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Free trial')->assertDontSee('Payment received');

        $this->travelTo(Carbon::parse('2026-12-10'));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    }

    public function test_paying_after_the_trial_ended_starts_the_period_on_the_day_it_is_confirmed(): void
    {
        [$shop, $owner] = $this->trialShop();
        $this->travelTo(Carbon::parse('2026-11-30 09:00'));

        $this->sendProof($owner);
        $this->confirmOpenPayment($shop);

        $shop->refresh();
        $invoice = SubscriptionInvoice::sole();
        $this->assertSame('2026-11-30', $shop->subscription_start_date->toDateString());
        $this->assertSame('2026-11-30', $invoice->period_start->toDateString());
        $this->assertSame('2026-12-29', $invoice->period_end->toDateString());
        $this->assertSame('Pro plan, 30 Nov – 29 Dec 2026', $invoice->items[0]['label']);
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
    }

    public function test_the_renewal_notice_opens_two_days_before_the_end_and_paying_in_time_carries_on(): void
    {
        [$shop, $owner] = $this->paidShop('2026-11-01');

        $this->travelTo(Carbon::parse('2026-11-28 12:00'));
        $this->assertSame(BillingPhase::Active, $this->cycle()->phase($shop));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('ending soon');
        $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertSee('Next payment')->assertSee('Rs 3,000.00');
        $this->assertSame(0, SubscriptionInvoice::count());

        $this->travelTo(Carbon::parse('2026-11-29 08:00'));
        $this->assertSame(BillingPhase::EndingSoon, $this->cycle()->phase($shop));
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertSee('Your subscription is ending soon')->assertSee('2 days left')->assertSee('Pay now');

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertSee('Complete your payment');
        $invoice = SubscriptionInvoice::sole();
        $this->assertSame('2026-12-01', $invoice->period_start->toDateString());
        $this->assertSame('3000.00', $invoice->total);
        $this->assertSame('Pro plan — renewal', $invoice->notes);
        $this->actingAs($owner)->get(route('billing.index'));
        $this->assertSame(1, SubscriptionInvoice::count());

        $this->sendProof($owner);
        $this->confirmOpenPayment($shop);

        $shop->refresh();
        $this->assertSame('2026-12-01', $shop->subscription_start_date->toDateString());
        $owner = $owner->fresh();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('ending soon');

        $this->travelTo(Carbon::parse('2026-12-05'));
        $this->assertSame(BillingPhase::Active, $this->cycle()->phase($shop));
        $this->actingAs($owner)->get(route('sales.index'))->assertOk();
    }

    public function test_an_unpaid_renewal_locks_the_shop_and_paying_late_starts_from_the_confirmation_day(): void
    {
        [$shop, $owner] = $this->paidShop('2026-11-01');

        $this->travelTo(Carbon::parse('2026-11-30 23:59'));
        $this->actingAs($owner)->get(route('sales.index'))->assertOk();

        $this->travelTo(Carbon::parse('2026-12-01 00:01'));
        $this->assertSame(BillingPhase::Lapsed, $this->cycle()->phase($shop));
        $this->actingAs($owner)->get(route('sales.index'))->assertRedirect(route('billing.index'));
        $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertSee('Your subscription ended on 01 Dec 2026')->assertSee('Every screen is paused');

        $this->travelTo(Carbon::parse('2026-12-04 10:00'));
        $this->sendProof($owner);
        $this->actingAs($owner)->get(route('sales.index'))->assertOk();
        $this->confirmOpenPayment($shop);

        $this->assertSame('2026-12-04', $shop->fresh()->subscription_start_date->toDateString());
        $this->assertSame('2026-12-04', SubscriptionInvoice::sole()->period_start->toDateString());
        $this->assertSame(BillingPhase::Active, $this->cycle()->phase($shop->fresh()));
    }

    public function test_the_admin_extends_a_trial_from_the_shop_page(): void
    {
        [$shop, $owner] = $this->trialShop();
        $admin = $this->admin();

        $this->travelTo(Carbon::parse('2026-11-28 10:00'));
        $this->actingAs($owner)->get(route('dashboard'))->assertRedirect(route('billing.index'));

        $this->actingAs($admin)->get(route('admin.shops.show', $shop))->assertOk()->assertSee('Extend trial');

        Livewire::actingAs($admin)->test('admin.shops.show', ['shop' => $shop])->set('extendDays', '0')->call('extendTrial')->assertHasErrors(['extendDays']);
        Livewire::actingAs($admin)->test('admin.shops.show', ['shop' => $shop])->set('extendDays', '7')->call('extendTrial')->assertHasNoErrors();

        $shop->refresh();
        $this->assertSame('2026-12-05 10:00:00', $shop->trial_ends_at->toDateTimeString());
        $this->assertSame('2026-12-05', SubscriptionInvoice::sole()->period_start->toDateString());
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk()->assertSee('7 days left');

        // Still on the trial: more days go on the end, not on today.
        Livewire::actingAs($admin)->test('admin.shops.show', ['shop' => $shop])->set('extendDays', '3')->call('extendTrial')->assertHasNoErrors();
        $this->assertSame('2026-12-08 10:00:00', $shop->fresh()->trial_ends_at->toDateTimeString());

        $this->actingAs($owner->fresh())->get(route('admin.shops.show', $shop))->assertForbidden();
        $this->assertSame('2026-12-08 10:00:00', $shop->fresh()->trial_ends_at->toDateTimeString());

        [$paid] = $this->paidShop();
        Livewire::actingAs($admin)->test('admin.shops.show', ['shop' => $paid])->set('extendDays', '5')->call('extendTrial');
        $this->assertNull($paid->fresh()->trial_ends_at);
    }

    public function test_shops_billed_by_hand_are_never_locked(): void
    {
        $plan = $this->plan();
        $inactive = Shop::create(['name' => 'Old signup', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'inactive', 'subscription_start_date' => '2026-01-01']);
        $stale = Shop::create(['name' => 'Old customer', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-01-01']);

        foreach ([$inactive, $stale] as $shop) {
            $this->assertSame(BillingPhase::Manual, $this->cycle()->phase($shop));
            $owner = User::factory()->create(['shop_id' => $shop->id]);
            $this->actingAs($owner)->get(route('sales.index'))->assertOk();
            $this->actingAs($owner)->get(route('billing.index'))->assertOk()->assertDontSee('Next payment');
        }

        $this->assertSame(0, SubscriptionInvoice::count());
    }

    public function test_the_admin_switches_a_shop_to_automatic_billing(): void
    {
        $plan = $this->plan();
        $shop = Shop::create(['name' => 'Old customer', 'plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-11-05']);

        Livewire::actingAs($this->admin())->test('admin.shops.show', ['shop' => $shop])->set('autoBilling', true)->call('saveSubscription')->assertHasNoErrors();

        $this->assertTrue($shop->fresh()->auto_billing);
        $this->assertSame(BillingPhase::Active, $this->cycle()->phase($shop->fresh()));
    }

    public function test_the_admin_keeps_payment_accounts_and_owners_see_them_when_paying(): void
    {
        [, $owner] = $this->trialShop();

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('PLACEHOLDER BANK DETAILS');
        $this->actingAs($owner)->get(route('admin.payment-accounts'))->assertForbidden();

        $admin = $this->admin();
        $this->actingAs($admin)->get(route('admin.payment-accounts'))->assertOk()->assertSee('No payment accounts yet');

        Livewire::actingAs($admin)->test('admin.payment-accounts')->call('openCreate')->set('kind', 'bank')->set('accountTitle', 'HisabOKitab')->set('accountNumber', '0101-2345')->call('save')->assertHasErrors(['provider']);
        Livewire::actingAs($admin)->test('admin.payment-accounts')->call('openCreate')->set('kind', 'bank')->set('provider', 'Meezan Bank')->set('accountTitle', 'HisabOKitab Pvt Ltd')->set('accountNumber', '0101-2345')->set('iban', 'PK36MEZN0001010123456789')->call('save')->assertHasNoErrors();
        Livewire::actingAs($admin)->test('admin.payment-accounts')->call('openCreate')->set('kind', 'jazzcash')->set('provider', 'ignored')->set('accountTitle', 'Owais A')->set('accountNumber', '0300-7654321')->set('iban', 'ignored')->call('save')->assertHasNoErrors();
        Livewire::actingAs($admin)->test('admin.payment-accounts')->call('openCreate')->set('kind', 'easypaisa')->set('accountTitle', 'Hidden one')->set('accountNumber', '0345-0000000')->set('isActive', false)->call('save')->assertHasNoErrors();

        $jazz = PaymentAccount::where('kind', 'jazzcash')->sole();
        $this->assertNull($jazz->provider);
        $this->assertNull($jazz->iban);

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Meezan Bank')->assertSee('PK36MEZN0001010123456789')->assertSee('JazzCash')->assertSee('0300-7654321')
            ->assertDontSee('Hidden one')->assertDontSee('PLACEHOLDER BANK DETAILS');
    }

    public function test_an_add_on_bought_in_a_paid_period_starts_now_and_its_part_cycle_is_added_to_the_next_payment(): void
    {
        [$shop, $owner] = $this->paidShop('2026-11-01');
        $ads = $this->unlock();

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('pro-rated')->assertSee('Rs 1,000.00');

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $ads->id)->assertHasNoErrors();

        $shopAddon = ShopAddon::sole();
        $this->assertSame(ShopAddonStatus::Active, $shopAddon->status);
        $this->assertTrue($shopAddon->plan_billed);
        $this->assertNull($shopAddon->subscription_invoice_id);
        $this->assertNull($shopAddon->ends_at);
        $this->assertSame('1000.00', $shopAddon->first_charge);
        $this->assertNull($shopAddon->first_charge_invoice_id);
        $this->assertTrue((bool) $shop->fresh()->ads_enabled);
        $this->assertSame(0, SubscriptionInvoice::count());
        $this->assertSame(0, SubscriptionPayment::count());

        $this->actingAs($owner)->get(route('billing.index'))->assertOk()
            ->assertSee('Next payment')->assertSee('Ads, 11 Nov – 30 Nov 2026 (part of a cycle)')->assertSee('Ads, 01 Dec – 31 Dec 2026')->assertSee('Rs 5,500.00');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk()->assertDontSee('Payment pending');

        $this->travelTo(Carbon::parse('2026-11-29 09:00'));
        $this->actingAs($owner)->get(route('billing.index'))->assertOk();

        $invoice = SubscriptionInvoice::sole();
        $this->assertSame('5500.00', $invoice->total);
        $this->assertSame(['plan', 'addon_part', 'addon'], array_column($invoice->items, 'kind'));
        $this->assertSame($invoice->id, $shopAddon->fresh()->first_charge_invoice_id);
        $this->assertSame('5500.00', SubscriptionPayment::sole()->amount);

        $this->sendProof($owner);
        $this->confirmOpenPayment($shop);
        // Next cycle: the plan and the add-on in full, nothing pro-rated left over.
        $this->travelTo(Carbon::parse('2026-12-30 09:00'));
        $this->actingAs($owner)->get(route('billing.index'));
        $this->assertSame(2, SubscriptionInvoice::count());
        $this->assertSame('4500.00', SubscriptionInvoice::latest('id')->first()->total);
    }

    public function test_an_add_on_bought_during_the_trial_is_free_until_the_first_payment_which_includes_it(): void
    {
        [$shop, $owner] = $this->trialShop();
        $ads = $this->unlock();
        $pack = $this->pack();

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('free for the rest of your trial');

        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $ads->id)->assertHasNoErrors();
        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $pack->id)->assertHasNoErrors();

        $this->assertTrue((bool) $shop->fresh()->ads_enabled);
        $this->assertSame(2, ShopAddon::where('status', 'active')->count());

        $invoice = SubscriptionInvoice::sole();
        $this->assertSame('5500.00', $invoice->total);
        $this->assertSame(['plan', 'addon', 'addon_part'], array_column($invoice->items, 'kind'));
        $this->assertSame('5500.00', SubscriptionPayment::sole()->amount);

        $packBought = ShopAddon::where('name', 'Extra 100 products')->sole();
        $this->assertSame('2026-12-26', $packBought->ends_at->toDateString());
        $this->assertSame($invoice->id, $packBought->first_charge_invoice_id);

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('Ads, 26 Nov – 25 Dec 2026')->assertSee('Extra 100 products, one-off')->assertSee('Rs 5,500.00');
    }

    public function test_add_ons_wait_while_a_payment_is_being_checked_and_cannot_be_bought_while_locked(): void
    {
        [$shop, $owner] = $this->trialShop();
        $ads = $this->unlock();

        $this->sendProof($owner);
        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $ads->id);
        $this->assertSame(0, ShopAddon::count());

        [$lapsed, $lapsedOwner] = $this->paidShop('2026-10-01');
        $this->assertTrue($this->cycle()->locked($lapsed));
        Livewire::actingAs($lapsedOwner)->test('billing.index')->call('buyAddon', $ads->id);
        $this->assertSame(0, ShopAddon::count());
    }

    public function test_the_pdf_lists_each_line_of_an_itemised_invoice(): void
    {
        [$shop, $owner] = $this->trialShop();
        Livewire::actingAs($owner)->test('billing.index')->call('buyAddon', $this->unlock()->id);

        $html = view('pdf.subscription-invoice', ['invoice' => SubscriptionInvoice::sole(), 'shop' => $shop, 'logo' => null, 'paymentInstructions' => null, 'supportContact' => null])->render();

        $this->assertStringContainsString('Pro plan, 26 Nov – 25 Dec 2026', $html);
        $this->assertStringContainsString('Ads, 26 Nov – 25 Dec 2026', $html);
        $this->assertStringContainsString('Rs 4,500.00', $html);
    }

    public function test_renewal_dates_never_spill_into_the_month_after(): void
    {
        [$shop] = $this->paidShop('2027-01-31');

        $this->assertSame('2027-02-28', $shop->expectedRenewalDate()->toDateString());
    }
}
