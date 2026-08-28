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
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login" class="space-y-5">
        <x-ui.field label="Email" name="form.email" for="email">
            <x-ui.input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username" />
        </x-ui.field>

        <x-ui.field label="Password" name="form.password" for="password">
            <x-ui.input wire:model="form.password" id="password" type="password" name="password" required autocomplete="current-password" />
        </x-ui.field>

        <div class="flex items-center">
            <label for="remember" class="inline-flex items-center">
                <input wire:model="form.remember" id="remember" type="checkbox" class="rounded border-slate-300 text-brand-600 shadow-sm focus:ring-brand-500" name="remember">
                <span class="ms-2 text-sm text-slate-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-between gap-4">
            @if (Route::has('password.request'))
                <a class="rounded-md text-sm text-slate-600 underline hover:text-slate-900 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-brand-500" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="login">
                {{ __('Log in') }}
            </x-ui.button>
        </div>
    </form>
</div>
