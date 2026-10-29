<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Shop;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SocialLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'g-id', 'services.google.client_secret' => 'g-secret',
            'services.microsoft.client_id' => 'm-id', 'services.microsoft.client_secret' => 'm-secret',
        ]);
    }

    protected function remote(string $provider, string $id, ?string $email, bool $verified = true, string $name = 'Sana Khan'): void
    {
        $user = (new SocialiteUser)->setRaw(['email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => $name]);
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andReturn($user);
        $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.example/authorize'));

        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    protected function existingOwner(string $email = 'sana@example.pk'): User
    {
        return User::factory()->create(['email' => $email, 'shop_id' => Shop::create(['name' => 'Sana Mobiles'])->id]);
    }

    public function test_buttons_show_only_for_configured_providers(): void
    {
        $this->get(route('login'))->assertSee('Continue with Google')->assertSee('Continue with Microsoft');

        config(['services.microsoft.client_id' => null]);
        $this->get(route('login'))->assertSee('Continue with Google')->assertDontSee('Continue with Microsoft');
        $this->get('/auth/microsoft/redirect')->assertNotFound();

        config(['services.google.client_id' => null]);
        $this->get(route('login'))->assertDontSee('Continue with');
    }

    public function test_google_auto_links_an_existing_account_by_verified_email(): void
    {
        $owner = $this->existingOwner();
        $this->remote('google', 'g-123', 'Sana@Example.PK');

        $this->get('/auth/google/redirect')->assertRedirect('https://accounts.example/authorize');
        $this->get('/auth/google/callback')->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-123']);

        auth()->logout();
        $owner->update(['email' => 'changed@example.pk']);
        $this->get('/auth/google/callback')->assertRedirect('/');
        $this->assertAuthenticatedAs($owner->fresh());
    }

    public function test_google_with_an_unverified_email_never_links(): void
    {
        $this->existingOwner();
        $this->remote('google', 'g-999', 'sana@example.pk', verified: false);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');

        $this->assertGuest();
        $this->assertSame(0, UserSocialAccount::count());
    }

    public function test_microsoft_never_links_by_email_and_must_be_connected_from_the_profile(): void
    {
        $owner = $this->existingOwner();
        $this->remote('microsoft', 'ms-1', 'sana@example.pk');

        $this->get('/auth/microsoft/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error', fn ($m) => str_contains($m, 'connect Microsoft from your profile'));
        $this->assertGuest();
        $this->assertSame(0, UserSocialAccount::count());

        $this->actingAs($owner);
        $this->get('/auth/microsoft/redirect?intent=link')->assertRedirect('https://accounts.example/authorize');
        $this->withSession(['oauth.intent' => 'link'])->get('/auth/microsoft/callback')->assertRedirect(route('profile'))->assertSessionHas('oauth_status');
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $owner->id, 'provider' => 'microsoft', 'provider_user_id' => 'ms-1']);

        auth()->logout();
        $this->get('/auth/microsoft/callback')->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);
    }

    public function test_an_account_already_linked_elsewhere_cannot_be_linked_again(): void
    {
        $first = $this->existingOwner('first@example.pk');
        $second = $this->existingOwner('second@example.pk');
        UserSocialAccount::create(['user_id' => $first->id, 'provider' => 'microsoft', 'provider_user_id' => 'ms-1']);
        $this->remote('microsoft', 'ms-1', 'first@example.pk');

        $this->actingAs($second)->withSession(['oauth.intent' => 'link'])->get('/auth/microsoft/callback')
            ->assertRedirect(route('profile'))->assertSessionHas('oauth_error');

        $this->assertSame($first->id, UserSocialAccount::sole()->user_id);
    }

    public function test_a_new_google_user_finishes_signup_without_a_code_or_password(): void
    {
        $plan = Plan::where('name', 'Commerce')->first();
        $this->remote('google', 'g-new', 'new.owner@example.pk', name: 'Hamza Ali');

        $this->get('/auth/google/redirect?intent=signup&plan='.$plan->id)->assertRedirect();
        $this->get('/auth/google/callback')->assertRedirect(route('site.signup.complete', ['plan' => $plan->id]));
        $this->assertGuest();

        $this->get(route('site.signup.complete', ['plan' => $plan->id]))->assertOk()->assertSee('new.owner@example.pk');

        Livewire::test('signup.complete', ['plan' => (string) $plan->id])
            ->assertSet('ownerName', 'Hamza Ali')
            ->set('shopName', 'Hamza Electronics')
            ->set('businessCategory', 'mobile_electronics')
            ->set('phone', '0321 5556667')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(route('billing.index'));

        $owner = User::sole();
        $this->assertAuthenticatedAs($owner);
        $this->assertSame('new.owner@example.pk', $owner->email);
        $this->assertNotNull($owner->email_verified_at);
        $this->assertNull($owner->password);
        $this->assertFalse($owner->hasPassword());
        $this->assertSame($plan->id, $owner->shop->plan_id);
        $this->assertDatabaseHas('user_social_accounts', ['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-new']);
        $this->assertNull(session('oauth.pending'));
    }

    public function test_the_completion_page_needs_a_pending_social_signup(): void
    {
        Livewire::test('signup.complete')->assertRedirect(route('site.signup'));
    }

    public function test_social_only_users_can_set_a_password_and_cannot_remove_their_last_login_method(): void
    {
        $owner = User::factory()->create(['shop_id' => Shop::create(['name' => 'A'])->id, 'password' => null]);
        UserSocialAccount::create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-1', 'email' => $owner->email]);

        $this->actingAs($owner)->get(route('profile'))->assertOk()->assertSee('Connected accounts')->assertDontSee('Current Password');

        Livewire::test('profile.connected-accounts')->call('disconnect', 'google');
        $this->assertSame(1, UserSocialAccount::count());

        Livewire::test('profile.update-password-form')->set('password', 'my-new-pass-1')->set('password_confirmation', 'my-new-pass-1')->call('updatePassword')->assertHasNoErrors();
        $this->assertTrue($owner->fresh()->hasPassword());

        Livewire::actingAs($owner->fresh())->test('profile.connected-accounts')->call('disconnect', 'google');
        $this->assertSame(0, UserSocialAccount::count());
    }

    public function test_inactive_users_cannot_log_in_through_a_provider(): void
    {
        $owner = $this->existingOwner();
        $owner->update(['is_active' => false]);
        UserSocialAccount::create(['user_id' => $owner->id, 'provider' => 'google', 'provider_user_id' => 'g-123']);
        $this->remote('google', 'g-123', 'sana@example.pk');

        $this->get('/auth/google/callback')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_failed_provider_callback_is_handled(): void
    {
        $driver = Mockery::mock();
        $driver->shouldReceive('user')->andThrow(new \Laravel\Socialite\Two\InvalidStateException);
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

        $this->get('/auth/google/callback')->assertRedirect(route('login'))->assertSessionHas('oauth_error');
        $this->assertGuest();
    }
}
