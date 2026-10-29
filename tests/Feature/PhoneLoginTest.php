<?php

namespace Tests\Feature;

use App\Enums\OtpPurpose;
use App\Models\Plan;
use App\Models\Role;
use App\Models\Shop;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'verification.whatsapp.phone_number_id' => '555',
            'verification.whatsapp.access_token' => 'wa-token',
            'verification.whatsapp.template' => 'hok_login_code',
            'verification.plivo.auth_id' => 'MA123',
            'verification.plivo.auth_token' => 'plivo-secret',
            'verification.plivo.sender' => '+15550001111',
        ]);
    }

    protected function fakeChannels(int $whatsappStatus = 200): void
    {
        Http::fake([
            'graph.facebook.com/*/555/messages' => Http::response($whatsappStatus === 200 ? ['messages' => [['id' => 'wamid.1']]] : ['error' => ['message' => 'Recipient not on WhatsApp']], $whatsappStatus),
            'api.plivo.com/*' => Http::response(['message_uuid' => ['u-1']], 202),
        ]);
    }

    protected function lastCode(): string
    {
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])->last(fn (Request $r) => str_contains($r->url(), '/messages') || str_contains($r->url(), 'plivo'));

        return str_contains($sent->url(), 'plivo')
            ? substr($sent['text'], 0, 6)
            : $sent['template']['components'][0]['parameters'][0]['text'];
    }

    protected function startPhoneSignup(array $overrides = [])
    {
        $component = Livewire::test('signup.form', ['plan' => (string) Plan::where('name', 'Growth')->value('id')]);
        $this->travel(5)->seconds();

        foreach (array_merge([
            'method' => 'phone',
            'shopName' => 'Bilal Telecom',
            'businessCategory' => 'mobile_electronics',
            'ownerName' => 'Bilal',
            'phone' => '0333 7654321',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ], $overrides) as $key => $value) {
            $component->set($key, $value);
        }

        return $component->call('submit');
    }

    public function test_a_shop_signs_up_with_a_mobile_number_verified_over_whatsapp_and_logs_in_with_it(): void
    {
        $this->fakeChannels();

        $component = $this->startPhoneSignup()->assertHasNoErrors()->assertSet('step', 'verify')->assertSet('sentVia', 'whatsapp')->assertSee('+92 333 7654321');

        $this->assertSame(0, User::count());
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/555/messages')
            && $r->hasHeader('Authorization', 'Bearer wa-token')
            && $r['to'] === '923337654321'
            && $r['template']['name'] === 'hok_login_code'
            && $r['template']['components'][1]['sub_type'] === 'url');
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'plivo'));

        $stored = VerificationCode::sole();
        $this->assertNotSame($this->lastCode(), $stored->code_hash);
        $this->assertStringNotContainsString($this->lastCode(), json_encode(session()->all()));

        $component->set('code', $this->lastCode())->call('verify')->assertHasNoErrors()->assertRedirect(route('billing.index'));

        $owner = User::sole();
        $this->assertSame('923337654321', $owner->phone);
        $this->assertNotNull($owner->phone_verified_at);
        $this->assertNull($owner->email);
        $this->assertNull($owner->email_verified_at);
        $this->assertTrue(Hash::check('secret-pass-1', $owner->password));
        $this->assertSame('+923337654321', $owner->shop->phone);
        $this->assertSame('+92 333 7654321', $owner->loginLabel());

        auth()->logout();

        foreach (['0333 7654321', '+92 333 7654321', '923337654321'] as $typed) {
            Volt::test('pages.auth.login')->set('form.email', $typed)->set('form.password', 'secret-pass-1')->call('login')->assertHasNoErrors();
            $this->assertAuthenticatedAs($owner);
            auth()->logout();
        }

        Volt::test('pages.auth.login')->set('form.email', '0333 7654321')->set('form.password', 'wrong-pass')->call('login')->assertHasErrors(['form.email']);
        $this->assertGuest();
    }

    public function test_sms_is_used_when_whatsapp_refuses_and_on_request(): void
    {
        $this->fakeChannels(400);

        $component = $this->startPhoneSignup()->assertHasNoErrors()->assertSet('sentVia', 'sms');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api.plivo.com/v1/Account/MA123/Message/')
            && $r['dst'] === '+923337654321'
            && $r['src'] === '+15550001111'
            && $r->hasHeader('Authorization', 'Basic '.base64_encode('MA123:plivo-secret')));

        $component->set('code', $this->lastCode())->call('verify')->assertHasNoErrors();
        $this->assertSame('923337654321', User::sole()->phone);
    }

    public function test_the_customer_can_ask_for_sms_instead_after_the_cooldown(): void
    {
        $this->fakeChannels();
        $component = $this->startPhoneSignup()->assertSet('sentVia', 'whatsapp');

        $component->call('resend', true)->assertHasErrors(['code']);

        $this->travel(61)->seconds();
        $component->call('resend', true)->assertHasNoErrors()->assertSet('sentVia', 'sms');

        $whatsappCode = collect(Http::recorded())->map(fn ($p) => $p[0])->first(fn ($r) => str_contains($r->url(), '/messages'))['template']['components'][0]['parameters'][0]['text'];
        $component->set('code', $whatsappCode)->call('verify')->assertHasErrors(['code']);

        $component->set('code', $this->lastCode())->call('verify')->assertHasNoErrors();
    }

    public function test_wrong_expired_and_brute_forced_codes_are_rejected(): void
    {
        $this->fakeChannels();
        $component = $this->startPhoneSignup();
        $code = $this->lastCode();
        $wrong = $code === '000000' ? '111111' : '000000';

        $component->set('code', $wrong)->call('verify')->assertHasErrors(['code']);
        $this->assertSame(1, VerificationCode::sole()->attempts);

        foreach (range(1, 4) as $i) {
            $component->set('code', $wrong)->call('verify');
        }

        $component->set('code', $code)->call('verify')->assertHasErrors(['code']);
        $this->assertSame(0, User::count());

        $this->travel(61)->seconds();
        $component->call('resend')->assertHasNoErrors();
        $fresh = $this->lastCode();
        $this->travel(11)->minutes();
        $component->set('code', $fresh)->call('verify')->assertHasErrors(['code']);
        $this->assertSame(0, User::count());
    }

    public function test_a_mobile_number_already_in_use_cannot_sign_up_again(): void
    {
        $this->fakeChannels();
        $shop = Shop::create(['name' => 'Existing']);
        User::factory()->create(['shop_id' => $shop->id, 'phone' => '923337654321']);

        $this->startPhoneSignup()->assertHasErrors(['phone']);
        Http::assertNothingSent();
    }

    public function test_phone_users_reset_their_password_with_a_code_and_unknown_numbers_look_the_same(): void
    {
        $this->fakeChannels();
        $shop = Shop::create(['name' => 'Existing']);
        $user = User::factory()->create(['shop_id' => $shop->id, 'email' => null, 'phone' => '923337654321']);

        $unknown = Volt::test('pages.auth.forgot-password')->set('email', '0300 0000001')->call('sendPasswordResetLink')->assertHasNoErrors();
        Http::assertNothingSent();
        $unknown->set('code', '123456')->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')->call('resetWithCode')->assertHasErrors(['code']);

        $known = Volt::test('pages.auth.forgot-password')->set('email', '0333-7654321')->call('sendPasswordResetLink')->assertHasNoErrors();
        $this->assertSame($unknown->get('phoneStep') !== '', $known->get('phoneStep') !== '');
        $this->assertSame(OtpPurpose::PasswordReset, VerificationCode::where('destination', '923337654321')->sole()->purpose);

        $known->set('code', $this->lastCode())->set('password', 'brand-new-pass')->set('password_confirmation', 'brand-new-pass')->call('resetWithCode')->assertHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('brand-new-pass', $user->fresh()->password));
    }

    public function test_email_password_reset_is_unchanged(): void
    {
        $this->fakeChannels();

        Volt::test('pages.auth.forgot-password')->set('email', 'not-an-email')->call('sendPasswordResetLink')->assertHasErrors(['email']);
        Volt::test('pages.auth.forgot-password')->set('email', 'someone@example.pk')->call('sendPasswordResetLink')->assertHasNoErrors()->assertSet('phoneStep', '');
    }

    public function test_owners_can_add_phone_only_staff_who_log_in_with_their_number(): void
    {
        $shop = Shop::create(['name' => 'Shop A']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);
        $role = Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['sales']]);

        $this->actingAs($owner);
        Volt::test('users.index')->call('openCreate')->set('name', 'Kashif')->set('roleId', $role->id)->call('save')->assertHasErrors(['email', 'phone']);
        Volt::test('users.index')->call('openCreate')->set('name', 'Kashif')->set('phone', '12345')->set('roleId', $role->id)->call('save')->assertHasErrors(['phone']);
        Volt::test('users.index')->call('openCreate')->set('name', 'Kashif')->set('phone', '0345 1112223')->set('roleId', $role->id)->call('save')->assertHasNoErrors();

        $staff = User::where('name', 'Kashif')->sole();
        $this->assertNull($staff->email);
        $this->assertSame('923451112223', $staff->phone);

        Volt::test('users.index')->call('openCreate')->set('name', 'Dup')->set('phone', '03451112223')->set('roleId', $role->id)->call('save')->assertHasErrors(['phone']);

        Volt::test('users.index')->call('openResetPassword', $staff->id)->set('newPassword', 'cashier-pass-1')->set('newPassword_confirmation', 'cashier-pass-1')->call('resetPassword');
        $this->get(route('users.index'))->assertSee('+92 345 1112223');

        auth()->logout();
        Volt::test('pages.auth.login')->set('form.email', '0345 1112223')->set('form.password', 'cashier-pass-1')->call('login')->assertHasNoErrors();
        $this->assertAuthenticatedAs($staff);
    }

    public function test_phone_signup_is_hidden_when_no_phone_channel_is_configured(): void
    {
        config(['verification.whatsapp.phone_number_id' => null, 'verification.plivo.auth_id' => null]);

        $this->get(route('site.signup'))->assertOk()->assertDontSee('role="radiogroup"', false);
        config(['verification.whatsapp.phone_number_id' => '555']);
        $this->get(route('site.signup'))->assertSee('role="radiogroup"', false);
        config(['verification.whatsapp.phone_number_id' => null]);
        Volt::test('pages.auth.forgot-password')->assertSee('Email Password Reset Link');
    }
}
