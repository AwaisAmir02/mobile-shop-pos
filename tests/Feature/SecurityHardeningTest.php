<?php

namespace Tests\Feature;

use App\Livewire\Forms\LoginForm;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_security_headers_and_hide_the_php_version(): void
    {
        foreach (['/', '/login', '/pricing'] as $path) {
            $this->get($path)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeaderMissing('X-Powered-By');
        }

        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=15552000');
    }

    public function test_public_routes_and_the_livewire_endpoint_are_throttled(): void
    {
        foreach (['home', 'site.pricing', 'site.feature', 'site.sitemap', 'login', 'password.request', 'webhooks.whatsapp.verify', 'site.signup', 'oauth.redirect'] as $name) {
            $throttles = collect(Route::getRoutes()->getByName($name)->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));
            $this->assertNotEmpty($throttles, "$name has no rate limit");
        }

        $livewire = Route::getRoutes()->getByName('livewire.update');
        $this->assertContains('throttle:livewire', $livewire->gatherMiddleware());
        $this->assertSame('livewire/update', $livewire->uri());
    }

    public function test_removing_a_screen_from_a_role_stops_actions_on_a_page_already_open(): void
    {
        $shop = \App\Models\Shop::create(['name' => 'Shop A']);
        $role = \App\Models\Role::create(['shop_id' => $shop->id, 'name' => 'Cashier', 'permissions' => ['expenses']]);
        $staff = User::factory()->staff()->create(['shop_id' => $shop->id, 'role_id' => $role->id]);
        $this->actingAs($staff);

        $html = $this->get(route('expenses.index'))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="(.*?)"\s/s', $html, $matches);
        $snapshot = collect($matches[1])->map(fn ($raw) => html_entity_decode($raw))
            ->first(fn ($json) => (json_decode($json, true)['memo']['name'] ?? null) === 'expenses.index');
        $this->assertNotNull($snapshot);

        $payload = ['_token' => csrf_token(), 'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]];
        $this->postJson('/livewire/update', $payload)->assertOk();

        $role->update(['permissions' => ['sales']]);
        $staff->unsetRelation('role');

        $this->postJson('/livewire/update', $payload)->assertForbidden();
    }

    public function test_one_address_failing_logins_across_many_accounts_is_stopped(): void
    {
        $victim = User::factory()->create();

        foreach (range(1, LoginForm::MAX_FAILURES_PER_IP) as $i) {
            Volt::test('pages.auth.login')->set('form.email', "nobody{$i}@example.com")->set('form.password', 'guess')->call('login');
        }

        // Even the right password waits now: the address is cooling off.
        Volt::test('pages.auth.login')
            ->set('form.email', $victim->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertHasErrors('form.email')
            ->assertNoRedirect();
        $this->assertGuest();

        RateLimiter::clear('login-ip|127.0.0.1');
        Volt::test('pages.auth.login')->set('form.email', $victim->email)->set('form.password', 'password')->call('login')->assertHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_a_successful_login_does_not_count_against_the_address(): void
    {
        $user = User::factory()->create();

        foreach (range(1, LoginForm::MAX_FAILURES_PER_IP + 5) as $i) {
            Volt::test('pages.auth.login')->set('form.email', $user->email)->set('form.password', 'password')->call('login')->assertHasNoErrors();
            auth()->logout();
        }
    }

    public function test_reset_links_from_one_address_are_capped(): void
    {
        Notification::fake();
        $users = User::factory()->count(6)->create();

        foreach ($users->take(5) as $user) {
            Volt::test('pages.auth.forgot-password')->set('email', $user->email)->call('sendPasswordResetLink')->assertHasNoErrors();
        }

        Volt::test('pages.auth.forgot-password')->set('email', $users->last()->email)->call('sendPasswordResetLink')->assertHasErrors('email');
        Notification::assertSentTimes(ResetPassword::class, 5);
    }

    public function test_debug_output_is_forced_off_in_production(): void
    {
        config(['app.debug' => true]);
        $this->app['env'] = 'production';

        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertFalse(config('app.debug'));
    }

    public function test_trusted_proxies_are_off_unless_configured(): void
    {
        $this->assertNull(config('app.trusted_proxies'));

        $this->get('/', ['X-Forwarded-For' => '203.0.113.9']);
        $this->assertSame('127.0.0.1', request()->ip());
    }
}
