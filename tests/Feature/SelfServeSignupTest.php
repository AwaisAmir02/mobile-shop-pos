<?php

namespace Tests\Feature;

use App\Enums\PlanType;
use App\Enums\SubscriptionInvoiceStatus;
use App\Enums\SubscriptionPaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Billing\ManualBankTransferGateway;
use App\Services\Billing\PaymentGateways;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use App\Mail\VerificationCodeMail;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SelfServeSignupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('signup-attempt:127.0.0.1');
        RateLimiter::clear('signup-hour:127.0.0.1');
        RateLimiter::clear('signup-day:127.0.0.1');
    }

    protected function plan(string $name = 'Growth'): Plan
    {
        return Plan::where('name', $name)->firstOrFail();
    }

    protected function signup(array $overrides = [], ?Plan $plan = null)
    {
        $plan ??= $this->plan();
        $component = Livewire::test('signup.form', ['plan' => (string) $plan->id]);
        $this->travel(5)->seconds();

        Mail::fake();

        foreach (array_merge([
            'shopName' => '  Ali Mobiles  ',
            'businessCategory' => 'mobile_electronics',
            'ownerName' => 'Ali Raza',
            'phone' => '0300 1234567',
            'email' => 'Ali@Example.PK',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides) as $key => $value) {
            $component->set($key, $value);
        }

        $component->call('submit');

        if ($component->errors()->isNotEmpty()) {
            return $component;
        }

        $code = Mail::sent(VerificationCodeMail::class)->last()->code;

        return $component->set('code', $code)->call('verify');
    }

    public function test_a_visitor_signs_up_and_gets_a_shop_owner_login_invoice_and_pending_payment(): void
    {
        $plan = $this->plan('Growth');

        $this->get('/pricing')->assertSee(route('site.signup', ['plan' => $plan->id]), false);
        $this->get(route('site.signup', ['plan' => $plan->id]))->assertOk()->assertSee('Continue');

        $this->signup()->assertHasNoErrors()->assertRedirect(route('billing.index'));

        $shop = Shop::sole();
        $this->assertSame('Ali Mobiles', $shop->name);
        $this->assertSame('+923001234567', $shop->phone);
        $this->assertSame('ali@example.pk', $shop->email);
        $this->assertSame($plan->id, $shop->plan_id);
        $this->assertSame(PlanType::Monthly, $shop->plan_type);
        $this->assertSame(SubscriptionStatus::Inactive, $shop->subscription_status);

        $owner = User::sole();
        $this->assertTrue($owner->is_owner);
        $this->assertSame($shop->id, $owner->shop_id);
        $this->assertSame('ali@example.pk', $owner->email);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNull($owner->phone);
        $this->assertAuthenticatedAs($owner);

        $invoice = SubscriptionInvoice::sole();
        $this->assertSame(SubscriptionInvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('4500.00', $invoice->total);
        $this->assertSame(1, $invoice->months);
        $this->assertSame($owner->id, $invoice->created_by);

        $payment = SubscriptionPayment::sole();
        $this->assertSame(ManualBankTransferGateway::KEY, $payment->gateway);
        $this->assertSame(SubscriptionPaymentStatus::Pending, $payment->status);
        $this->assertSame('4500.00', $payment->amount);
        $this->assertNull($payment->submitted_at);

        $this->get(route('billing.index'))->assertOk()->assertSee('Rs 4,500.00')->assertSee('PLACEHOLDER BANK DETAILS')->assertSee($invoice->invoice_number);
        $this->get(route('dashboard'))->assertOk()->assertSee('Payment pending.');
    }

    public function test_a_yearly_plan_bills_twelve_months(): void
    {
        $yearly = Plan::create(['name' => 'Annual', 'price' => 45000, 'billing_interval' => 'yearly', 'features' => [['label' => 'All', 'included' => true]], 'sort_order' => 9]);

        $this->signup([], $yearly)->assertHasNoErrors();

        $this->assertSame(PlanType::Yearly, Shop::sole()->plan_type);
        $this->assertSame(12, SubscriptionInvoice::sole()->months);
        $this->assertSame('45000.00', SubscriptionPayment::sole()->amount);
    }

    public function test_bots_are_stopped_by_the_honeypot_and_the_minimum_fill_time(): void
    {
        $this->signup(['website' => 'http://spam.example'])->assertHasErrors(['form']);

        Livewire::test('signup.form', ['plan' => (string) $this->plan()->id])
            ->set('shopName', 'Fast Bot')->set('ownerName', 'Bot')->set('phone', '03001234567')
            ->set('email', 'bot@example.pk')->set('password', 'secret-pass-1')->set('password_confirmation', 'secret-pass-1')
            ->call('submit')
            ->assertHasErrors(['form']);

        $this->assertSame(0, Shop::count());
        $this->assertGuest();
    }

    public function test_input_is_validated(): void
    {
        User::factory()->create(['email' => 'taken@example.pk', 'shop_id' => Shop::create(['name' => 'Other'])->id]);
        $inactive = Plan::create(['name' => 'Old', 'price' => 1, 'billing_interval' => 'monthly', 'features' => [['label' => 'x', 'included' => true]], 'is_active' => false]);

        $this->signup(['phone' => '12345'])->assertHasErrors(['phone']);
        $this->signup(['phone' => '0423 5555555'])->assertHasErrors(['phone']);
        $this->signup(['email' => 'TAKEN@example.pk'])->assertHasErrors(['email']);
        $this->signup(['password' => 'short', 'password_confirmation' => 'short'])->assertHasErrors(['password']);
        $this->signup(['password_confirmation' => 'different-1'])->assertHasErrors(['password']);
        $this->signup(['shopName' => ''])->assertHasErrors(['shopName']);
        $this->signup(['planId' => (string) $inactive->id])->assertHasErrors(['planId']);

        $this->assertSame(1, Shop::count());
    }

    public function test_signups_per_ip_are_capped(): void
    {
        foreach (range(1, 3) as $i) {
            $this->signup(['email' => "owner{$i}@example.pk"])->assertHasNoErrors();
            auth()->logout();
        }

        $this->signup(['email' => 'owner4@example.pk'])->assertHasErrors(['form']);
        $this->assertSame(3, Shop::count());
    }

    public function test_turnstile_is_enforced_when_configured(): void
    {
        config(['billing.turnstile.site_key' => 'site', 'billing.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => Http::sequence()->push(['success' => false])->push(['success' => true])]);

        $this->get(route('site.signup'))->assertSee('challenges.cloudflare.com/turnstile', false);

        $this->signup(['turnstileToken' => 'bad'])->assertHasErrors(['form']);
        $this->assertSame(0, Shop::count());

        $this->signup(['turnstileToken' => 'good'])->assertHasNoErrors();
        $this->assertSame(1, Shop::count());
        Http::assertSent(fn ($r) => $r['secret'] === 'secret' && $r['response'] === 'good');
    }

    public function test_logged_in_users_are_sent_away_from_signup(): void
    {
        $this->actingAs(User::factory()->create(['shop_id' => Shop::create(['name' => 'A'])->id]));

        $this->get(route('site.signup'))->assertRedirect();
    }

    public function test_the_owner_submits_a_reference_and_screenshot_and_staff_cannot_see_billing(): void
    {
        Storage::fake('local');
        $this->signup()->assertHasNoErrors();
        $owner = User::sole();

        Livewire::actingAs($owner)->test('billing.index')
            ->set('reference', 'TID-99887766')
            ->set('proof', UploadedFile::fake()->image('transfer.png'))
            ->call('submitProof')
            ->assertHasNoErrors();

        $payment = SubscriptionPayment::sole();
        $this->assertSame('TID-99887766', $payment->reference);
        $this->assertNotNull($payment->submitted_at);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertStringStartsWith('payment-proofs/'.$owner->shop_id.'/', $payment->proof_path);

        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Payment received — we’re confirming it.', false);

        Livewire::actingAs($owner)->test('billing.index')
            ->set('reference', 'x')
            ->set('proof', UploadedFile::fake()->create('evil.php', 10, 'application/x-php'))
            ->call('submitProof')
            ->assertHasErrors(['reference', 'proof']);

        $role = Role::create(['shop_id' => $owner->shop_id, 'name' => 'Cashier', 'permissions' => ['sales', 'dashboard']]);
        $staff = User::factory()->staff()->create(['shop_id' => $owner->shop_id, 'role_id' => $role->id]);
        $this->actingAs($staff)->get(route('billing.index'))->assertForbidden();
        $this->actingAs($staff)->get(route('dashboard'))->assertDontSee('Payment pending')->assertDontSee('Payment received');
    }

    public function test_the_owner_downloads_only_their_own_invoice(): void
    {
        $this->signup()->assertHasNoErrors();
        $owner = User::sole();
        $mine = SubscriptionInvoice::sole();

        $other = Shop::create(['name' => 'Other shop', 'plan_type' => 'monthly']);
        $theirs = app(\App\Services\SubscriptionBillingService::class)->create($other, ['period_start' => now()->toDateString(), 'months' => 1, 'amount' => 100], null);

        $this->actingAs($owner)->get(route('billing.invoices.pdf', $mine))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($owner)->get(route('billing.invoices.pdf', $theirs))->assertNotFound();
    }

    public function test_the_super_admin_confirms_a_payment_and_the_shop_becomes_active(): void
    {
        Storage::fake('local');
        $this->signup()->assertHasNoErrors();
        $owner = User::sole();
        Livewire::actingAs($owner)->test('billing.index')->set('reference', 'TID-1234')->set('proof', UploadedFile::fake()->image('t.jpg'))->call('submitProof');
        auth()->logout();

        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $payment = SubscriptionPayment::sole();

        $this->actingAs($admin)->get(route('admin.payments'))->assertOk()->assertSee('Ali Mobiles')->assertSee('TID-1234');
        $this->actingAs($admin)->get(route('admin.payments.proof', $payment))->assertOk();
        $this->actingAs($owner)->get(route('admin.payments.proof', $payment))->assertForbidden();

        Livewire::actingAs($admin)->test('admin.payments')->call('confirm', $payment->id);

        $payment->refresh();
        $this->assertSame(SubscriptionPaymentStatus::Confirmed, $payment->status);
        $this->assertSame($admin->id, $payment->reviewed_by);
        $this->assertSame(SubscriptionInvoiceStatus::Paid, $payment->invoice->fresh()->status);
        $this->assertTrue($payment->shop->fresh()->isSubscriptionActive());

        Livewire::actingAs($admin)->test('admin.payments')->call('confirm', $payment->id);
        $this->assertSame(1, SubscriptionInvoice::where('status', 'paid')->count());

        $this->actingAs($owner)->get(route('dashboard'))->assertDontSee('Payment pending')->assertDontSee('Payment received');
        $this->actingAs($owner)->get(route('billing.index'))->assertSee('no payment due');
    }

    public function test_a_rejected_payment_shows_the_reason_and_can_be_resubmitted(): void
    {
        $this->signup()->assertHasNoErrors();
        $owner = User::sole();
        Livewire::actingAs($owner)->test('billing.index')->set('reference', 'TID-WRONG')->call('submitProof');

        $admin = User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
        $payment = SubscriptionPayment::sole();

        Livewire::actingAs($admin)->test('admin.payments')->call('openReject', $payment->id)->set('rejectReason', 'x')->call('reject')->assertHasErrors(['rejectReason']);
        Livewire::actingAs($admin)->test('admin.payments')->call('openReject', $payment->id)->set('rejectReason', 'No transfer found with this reference')->call('reject')->assertHasNoErrors();

        $this->assertSame(SubscriptionPaymentStatus::Rejected, $payment->fresh()->status);
        $this->assertFalse($payment->shop->fresh()->isSubscriptionActive());

        $this->actingAs($owner)->get(route('billing.index'))->assertSee('No transfer found with this reference');
        $this->actingAs($owner)->get(route('dashboard'))->assertSee('Payment not confirmed.');

        Livewire::actingAs($owner)->test('billing.index')->set('reference', 'TID-RIGHT')->call('submitProof')->assertHasNoErrors();
        $payment->refresh();
        $this->assertSame(SubscriptionPaymentStatus::Pending, $payment->status);
        $this->assertSame('TID-RIGHT', $payment->reference);
        $this->assertNull($payment->rejection_reason);
    }

    public function test_only_the_super_admin_can_review_payments(): void
    {
        $this->signup()->assertHasNoErrors();
        $owner = User::sole();
        $payment = SubscriptionPayment::sole();

        $this->actingAs($owner)->get(route('admin.payments'))->assertForbidden();
        Livewire::actingAs($owner)->test('admin.payments')->assertForbidden();

        $this->assertSame(SubscriptionPaymentStatus::Pending, $payment->fresh()->status);
    }

    public function test_the_gateway_is_resolved_from_config(): void
    {
        $this->assertInstanceOf(ManualBankTransferGateway::class, app(PaymentGateways::class)->current());

        $this->expectException(\InvalidArgumentException::class);
        app(PaymentGateways::class)->for('unknown');
    }
}
