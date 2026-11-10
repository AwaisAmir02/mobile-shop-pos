<?php

namespace App\Livewire\Forms;

use App\Support\PhoneNumber;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Validate;
use Livewire\Form;

class LoginForm extends Form
{
    /** Failed logins from one address, across every account, before it has to wait. Stops credential stuffing. */
    public const MAX_FAILURES_PER_IP = 20;

    public const IP_WINDOW_SECONDS = 900;

    #[Validate('required|string|max:255')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    #[Validate('boolean')]
    public bool $remember = false;

    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt([...$this->credentials(), 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($this->throttleKey());
            RateLimiter::hit($this->ipThrottleKey(), self::IP_WINDOW_SECONDS);

            throw ValidationException::withMessages([
                'form.email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    protected function credentials(): array
    {
        $login = trim($this->email);

        if (str_contains($login, '@')) {
            return ['email' => $login];
        }

        return ['phone' => PhoneNumber::isPakistaniMobile($login) ? PhoneNumber::normalize($login) : $login];
    }

    protected function ensureIsNotRateLimited(): void
    {
        $key = match (true) {
            RateLimiter::tooManyAttempts($this->throttleKey(), 5) => $this->throttleKey(),
            RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_FAILURES_PER_IP) => $this->ipThrottleKey(),
            default => null,
        };

        if ($key === null) {
            return;
        }

        event(new Lockout(request()));

        $seconds = RateLimiter::availableIn($key);

        throw ValidationException::withMessages([
            'form.email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    protected function ipThrottleKey(): string
    {
        return 'login-ip|'.request()->ip();
    }

    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower((string) head($this->credentials())).'|'.request()->ip());
    }
}
