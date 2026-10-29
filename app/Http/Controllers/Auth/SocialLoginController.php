<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

class SocialLoginController extends Controller
{
    public const PENDING_KEY = 'oauth.pending';

    protected const INTENTS = ['login', 'signup', 'link'];

    public function redirect(Request $request, string $provider): SymfonyRedirect
    {
        $social = $this->provider($provider);
        $intent = in_array($request->query('intent'), self::INTENTS, true) ? $request->query('intent') : 'login';

        if ($intent === 'link' && ! $request->user()) {
            return redirect()->route('login');
        }

        if ($intent !== 'link' && $request->user()) {
            return redirect('/');
        }

        session(['oauth.intent' => $intent, 'oauth.plan' => $request->integer('plan') ?: null]);

        return Socialite::driver($social->value)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $social = $this->provider($provider);
        $intent = session()->pull('oauth.intent', 'login');
        $plan = session()->pull('oauth.plan');

        try {
            $remote = Socialite::driver($social->value)->user();
        } catch (\Throwable $e) {
            report($e);

            return $this->fail($intent, 'We couldn’t sign you in with '.$social->label().'. Please try again.');
        }

        $id = (string) $remote->getId();
        $email = mb_strtolower(trim((string) $remote->getEmail())) ?: null;
        $emailVerified = $email !== null && match ($social) {
            SocialProvider::Google => (bool) ($remote->user['email_verified'] ?? $remote->user['verified_email'] ?? false),
            SocialProvider::Microsoft => true,
        };

        if ($id === '') {
            return $this->fail($intent, 'We couldn’t sign you in with '.$social->label().'. Please try again.');
        }

        $linked = UserSocialAccount::where('provider', $social->value)->where('provider_user_id', $id)->first();

        if ($intent === 'link') {
            return $this->link($request->user(), $social, $id, $email, $linked);
        }

        if ($linked) {
            return $this->login($linked->user, $social);
        }

        $existing = $email ? User::where('email', $email)->first() : null;

        if ($existing && $emailVerified && $social->autoLinksByEmail()) {
            UserSocialAccount::create(['user_id' => $existing->id, 'provider' => $social->value, 'provider_user_id' => $id, 'email' => $email]);

            if (! $existing->email_verified_at) {
                $existing->forceFill(['email_verified_at' => now()])->save();
            }

            return $this->login($existing, $social);
        }

        if ($existing) {
            return $this->fail('login', 'An account with '.$email.' already exists. Log in with your password, then connect '.$social->label().' from your profile.');
        }

        if (! $emailVerified) {
            return $this->fail($intent, 'Your '.$social->label().' account doesn’t have a verified email address. Please sign up with email or mobile number instead.');
        }

        session([self::PENDING_KEY => [
            'provider' => $social->value,
            'provider_user_id' => $id,
            'email' => $email,
            'name' => (string) ($remote->getName() ?? ''),
        ]]);

        return redirect()->route('site.signup.complete', array_filter(['plan' => $plan]));
    }

    protected function link(?User $user, SocialProvider $social, string $id, ?string $email, ?UserSocialAccount $linked): RedirectResponse
    {
        if (! $user) {
            return redirect()->route('login');
        }

        if ($linked && $linked->user_id !== $user->id) {
            return redirect()->route('profile')->with('oauth_error', 'This '.$social->label().' account is already connected to a different login.');
        }

        UserSocialAccount::updateOrCreate(
            ['user_id' => $user->id, 'provider' => $social->value],
            ['provider_user_id' => $id, 'email' => $email],
        );

        return redirect()->route('profile')->with('oauth_status', $social->label().' connected. You can now log in with it.');
    }

    protected function login(User $user, SocialProvider $social): RedirectResponse
    {
        if (! $user->is_active) {
            return $this->fail('login', trans('auth.failed'));
        }

        Auth::login($user);
        session()->regenerate();

        return redirect('/');
    }

    protected function fail(string $intent, string $message): RedirectResponse
    {
        return redirect()->route($intent === 'signup' ? 'site.signup' : 'login')->with('oauth_error', $message);
    }

    protected function provider(string $provider): SocialProvider
    {
        $social = SocialProvider::tryFrom($provider);

        abort_unless($social && $social->isConfigured(), 404);

        return $social;
    }
}
