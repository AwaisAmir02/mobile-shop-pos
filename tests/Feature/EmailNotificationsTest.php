<?php

namespace Tests\Feature;

use App\Enums\OtpChannel;
use App\Enums\OtpPurpose;
use App\Mail\LowStockMail;
use App\Mail\NewSignupMail;
use App\Mail\PaymentReviewedMail;
use App\Mail\RenewalReminderMail;
use App\Mail\VerificationCodeMail;
use App\Models\Addon;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Auth\VerificationCodes;
use App\Services\Billing\BillingCycle;
use App\Services\Billing\SubscriptionPaymentService;
use App\Support\ShopOwnerEmail;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Addon::query()->delete();
        $this->travelTo(Carbon::parse('2026-11-11 10:00:00'));
    }

    protected function plan(): Plan
    {
        return Plan::create(['name' => 'Pro', 'price' => 3000, 'billing_interval' => 'monthly', 'features' => [], 'limits' => [], 'feature_flags' => [], 'trial_days' => 15, 'is_active' => true]);
    }

    /** @return array{0: Shop, 1: User} */
    protected function shop(array $owner = ['email' => 'owner@example.pk'], array $shop = []): array
    {
        $shop = Shop::create($shop + ['name' => 'Ali Mobiles']);
        $user = User::factory()->create($owner + ['shop_id' => $shop->id, 'is_owner' => true]);

        return [$shop, $user];
    }

    protected function product(Shop $shop, int $stock, string $name = 'Charger'): Product
    {
        return Product::create(['shop_id' => $shop->id, 'type' => 'accessory', 'name' => $name, 'price' => 100, 'stock_quantity' => $stock, 'details' => []]);
    }

    // ── Resend wiring ─────────────────────────────────────────────────

    public function test_mail_mailer_resend_resolves_the_resend_transport_with_the_key_from_env(): void
    {
        config(['mail.default' => 'resend', 'services.resend.key' => 're_test_123']);

        $this->assertInstanceOf(ResendTransport::class, Mail::mailer()->getSymfonyTransport());
        $this->assertSame('resend', config('mail.default'));
    }

    public function test_a_failing_provider_turns_the_signup_code_into_a_friendly_error(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('Resend: API key is invalid'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('We couldn’t send the code to this email right now.');

        app(VerificationCodes::class)->send(OtpPurpose::Signup, OtpChannel::Email, 'new@example.pk', '127.0.0.1');
    }

    // ── Owner address ─────────────────────────────────────────────────

    public function test_shop_email_goes_to_the_owner_then_the_shop_then_nowhere(): void
    {
        [$shop] = $this->shop(['email' => 'owner@example.pk'], ['email' => 'shop@example.pk']);
        $this->assertSame('owner@example.pk', ShopOwnerEmail::for($shop));

        [$phoneShop] = $this->shop(['email' => null, 'phone' => '923001111111'], ['email' => 'shop2@example.pk']);
        $this->assertSame('shop2@example.pk', ShopOwnerEmail::for($phoneShop));

        [$bare] = $this->shop(['email' => null, 'phone' => '923002222222']);
        $this->assertNull(ShopOwnerEmail::for($bare));
    }

    // ── Low stock ─────────────────────────────────────────────────────

    public function test_dropping_into_low_stock_emails_the_owner_once(): void
    {
        Mail::fake();
        [$shop] = $this->shop();
        $product = $this->product($shop, 8);

        $product->decrement('stock_quantity', 2);
        Mail::assertNothingQueued();

        $product->decrement('stock_quantity', 2);
        Mail::assertQueued(LowStockMail::class, fn (LowStockMail $mail) => $mail->hasTo('owner@example.pk')
            && $mail->items === [['name' => 'Charger', 'stock' => '4']]);

        $product->decrement('stock_quantity', 1);
        Mail::assertQueuedCount(1);
    }

    public function test_selling_out_or_restocking_low_sends_nothing(): void
    {
        Mail::fake();
        [$shop] = $this->shop();
        $this->product($shop, 10)->decrement('stock_quantity', 10);
        $this->product($shop, 0, 'Cable')->increment('stock_quantity', 3);

        Mail::assertNothingQueued();
    }

    public function test_products_going_low_in_one_transaction_share_one_email(): void
    {
        Mail::fake();
        [$shop] = $this->shop();
        $a = $this->product($shop, 7, 'Charger');
        $b = $this->product($shop, 9, 'Cable');

        DB::transaction(function () use ($a, $b) {
            $a->decrement('stock_quantity', 3);
            $b->decrement('stock_quantity', 5);
        });

        Mail::assertQueuedCount(1);
        Mail::assertQueued(LowStockMail::class, fn (LowStockMail $mail) => count($mail->items) === 2);
        $this->assertStringContainsString('2 products are running low', (new LowStockMail($shop, [['name' => 'A', 'stock' => '1'], ['name' => 'B', 'stock' => '2']]))->render());
    }

    public function test_a_rolled_back_sale_sends_no_low_stock_email(): void
    {
        Mail::fake();
        [$shop] = $this->shop();
        $product = $this->product($shop, 8);

        try {
            DB::transaction(function () use ($product) {
                $product->decrement('stock_quantity', 5);
                throw new \LogicException('rolled back');
            });
        } catch (\LogicException) {
        }

        Mail::assertNothingQueued();

        // The dropped batch doesn't block the next real one.
        $product = $product->fresh();
        DB::transaction(fn () => $product->decrement('stock_quantity', 5));
        Mail::assertQueued(LowStockMail::class, fn (LowStockMail $mail) => $mail->items === [['name' => 'Charger', 'stock' => '3']]);
    }

    // ── Payments ──────────────────────────────────────────────────────

    public function test_confirming_and_rejecting_a_payment_email_the_owner(): void
    {
        Mail::fake();
        [$shop, $owner] = app(SubscriptionPaymentService::class)->register(
            $this->plan(),
            ['name' => 'Ali Mobiles', 'phone' => '+923001234567'],
            ['name' => 'Ali Raza', 'email' => 'ali@example.pk', 'password' => 'secret-pass-1'],
            emailVerified: true,
        );
        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $service = app(SubscriptionPaymentService::class);
        $payment = SubscriptionPayment::where('shop_id', $shop->id)->sole();

        $service->submitProof($payment, 'TID-1', null);
        $rejected = $service->reject($payment, $admin->id, 'Amount does not match');

        Mail::assertQueued(PaymentReviewedMail::class, fn (PaymentReviewedMail $mail) => $mail->hasTo('ali@example.pk') && ! $mail->confirmed());
        $this->assertStringContainsString('Amount does not match', (new PaymentReviewedMail($rejected))->render());

        $service->submitProof($payment, 'TID-2', null);
        $confirmed = $service->confirm($payment, $admin->id);

        Mail::assertQueued(PaymentReviewedMail::class, fn (PaymentReviewedMail $mail) => $mail->confirmed());
        $this->assertStringContainsString('Payment confirmed', (new PaymentReviewedMail($confirmed))->render());
    }

    // ── Signup ────────────────────────────────────────────────────────

    public function test_a_self_serve_signup_alerts_the_platform_inbox(): void
    {
        Mail::fake();
        config(['notifications.signup_alert_email' => 'admin@example.pk']);

        [$shop] = app(SubscriptionPaymentService::class)->register(
            $this->plan(),
            ['name' => 'Ali Mobiles', 'phone' => '+923001234567'],
            ['name' => 'Ali Raza', 'email' => 'ali@example.pk', 'password' => 'secret-pass-1'],
            emailVerified: true,
        );

        Mail::assertQueued(NewSignupMail::class, fn (NewSignupMail $mail) => $mail->hasTo('admin@example.pk') && $mail->shop->is($shop));
        $html = Mail::queued(NewSignupMail::class)->first()->render();
        $this->assertStringContainsString('Ali Raza', $html);
        $this->assertStringContainsString(route('admin.shops.show', $shop), $html);
    }

    public function test_an_empty_signup_alert_address_turns_it_off(): void
    {
        Mail::fake();
        config(['notifications.signup_alert_email' => '']);

        app(SubscriptionPaymentService::class)->register(
            $this->plan(),
            ['name' => 'Ali Mobiles', 'phone' => '+923001234567'],
            ['name' => 'Ali Raza', 'email' => 'ali@example.pk', 'password' => 'secret-pass-1'],
        );

        Mail::assertNotQueued(NewSignupMail::class);
    }

    // ── Renewal ───────────────────────────────────────────────────────

    public function test_ending_soon_emails_the_owner_once_per_renewal(): void
    {
        Mail::fake();
        $plan = $this->plan();
        [$shop] = $this->shop(shop: ['plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-10-13', 'auto_billing' => true]);

        // Renews 13 Nov, notice window opens 11 Nov: today.
        $this->assertSame('ending_soon', app(BillingCycle::class)->phase($shop->fresh())->value);

        $this->artisan('billing:renewal-reminders')->assertSuccessful();
        $this->artisan('billing:renewal-reminders')->assertSuccessful();

        Mail::assertQueuedCount(1);
        Mail::assertQueued(RenewalReminderMail::class, fn (RenewalReminderMail $mail) => $mail->hasTo('owner@example.pk') && $mail->daysLeft === 2);
        $this->assertSame('2026-11-13', $shop->fresh()->renewal_reminder_sent_for);
        $this->assertStringContainsString('12 Nov 2026', Mail::queued(RenewalReminderMail::class)->first()->render());
    }

    public function test_no_renewal_email_outside_the_window_after_paying_or_on_manual_billing(): void
    {
        Mail::fake();
        $plan = $this->plan();
        $this->shop(['email' => 'a@example.pk'], ['plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-11-01', 'auto_billing' => true]);
        $this->shop(['email' => 'b@example.pk'], ['plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-10-13', 'auto_billing' => false]);
        [$paying] = $this->shop(['email' => 'c@example.pk'], ['plan_id' => $plan->id, 'plan_type' => 'monthly', 'subscription_status' => 'active', 'subscription_start_date' => '2026-10-13', 'auto_billing' => true]);

        $payment = app(BillingCycle::class)->openPayment($paying->fresh());
        app(SubscriptionPaymentService::class)->submitProof($payment, 'TID-9', null);

        $this->artisan('billing:renewal-reminders')->assertSuccessful();

        Mail::assertNotQueued(RenewalReminderMail::class);
    }

    // ── Existing paths still render through the default mailer ────────

    public function test_the_verification_code_still_goes_through_the_default_mailer(): void
    {
        Mail::fake();

        app(VerificationCodes::class)->send(OtpPurpose::Signup, OtpChannel::Email, 'new@example.pk', '127.0.0.1');

        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('new@example.pk'));
    }
}
