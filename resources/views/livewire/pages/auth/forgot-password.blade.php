<?php

use Illuminate\Support\Facades\Password;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public string $email = '';

    public function sendPasswordResetLink(): void
    {
        $this->validate([
            'email' => ['required', 'string', 'email'],
        ]);

        // Intentionally ignore the broker's status: whether the email matches an
        // account or not, the response to the user must be identical, otherwise
        // this form becomes a way to test which emails have accounts.
        Password::sendResetLink($this->only('email'));

        $this->reset('email');

        session()->flash('status', __('passwords.sent'));
    }
}; ?>

<div>
    <div class="mb-4 text-sm text-slate-600">
        {{ __('Forgot your password? No problem. Just let us know your email address and we will email you a password reset link that will allow you to choose a new one.') }}
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="sendPasswordResetLink" class="space-y-5">
        <x-ui.field label="Email" name="email">
            <x-ui.input wire:model="email" id="email" type="email" name="email" required autofocus />
        </x-ui.field>

        <div class="flex items-center justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="sendPasswordResetLink">
                {{ __('Email Password Reset Link') }}
            </x-ui.button>
        </div>
    </form>
</div>
