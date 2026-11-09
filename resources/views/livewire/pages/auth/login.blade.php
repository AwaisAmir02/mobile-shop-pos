<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $default = match (true) {
            Auth::user()->is_super_admin => route('admin.dashboard', absolute: false),
            Auth::user()->hasAccessTo('dashboard') => route('dashboard', absolute: false),
            default => route('welcome', absolute: false),
        };

        $this->redirectIntended(default: $default, navigate: true);
    }
}; ?>

<div>
    <div class="mb-6 text-center">
        <h1 class="text-2xl font-extrabold tracking-tight text-ink">Welcome back</h1>
        <p class="mt-1 text-sm text-ink-faint">Log in to your shop.</p>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    @if (session('oauth_error'))
        <p class="mb-4 rounded-lg border border-danger/20 bg-danger-soft px-3 py-2 text-sm text-danger" role="alert">{{ session('oauth_error') }}</p>
    @endif

    <x-signup.social-buttons intent="login" class="mb-5" />

    <form wire:submit="login" class="space-y-5">
        <x-ui.field label="Email or mobile number" name="form.email" for="email">
            <x-ui.input wire:model="form.email" id="email" type="text" name="email" required autofocus autocomplete="username" />
        </x-ui.field>

        <x-ui.field label="Password" name="form.password" for="password">
            <x-ui.input wire:model="form.password" id="password" type="password" name="password" required autocomplete="current-password" />
        </x-ui.field>

        <div class="flex items-center">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-line-strong text-primary shadow-soft focus:ring-link" name="remember">
                <span class="ms-2 text-sm text-ink-muted">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-between gap-4">
            @if (Route::has('password.request'))
                <a class="rounded-md text-sm font-medium text-link hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-link" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="login">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>

    @if (Route::has('site.signup'))
        <p class="mt-6 border-t border-line pt-5 text-center text-sm text-ink-faint">
            New to {{ config('branding.name') }}?
            <a href="{{ route('site.signup') }}" class="font-semibold text-link hover:underline">Create your shop</a>
        </p>
    @endif
</div>
