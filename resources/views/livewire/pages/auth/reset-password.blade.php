<?php

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    #[Locked]
    public string $token = '';
    public string $email = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $this->email = request()->string('email');
    }

    public function resetPassword(): void
    {
        $this->validate([
            'token' => ['required'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ]);

        $status = Password::reset(
            $this->only('email', 'password', 'password_confirmation', 'token'),
            function ($user) {
                $user->forceFill([
                    'password' => Hash::make($this->password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status != Password::PASSWORD_RESET) {
            // Deliberately generic: distinguishing "bad token" from "no such
            // account" here would let this form be used to test which emails
            // have accounts, the same leak fixed on the forgot-password step.
            $this->addError('email', __('This password reset link is invalid or has expired.'));

            return;
        }

        Session::flash('status', __($status));

        $this->redirectRoute('login', navigate: true);
    }
}; ?>

<div>
    <form wire:submit="resetPassword" class="space-y-5">
        <x-ui.field label="Email" name="email">
            <x-ui.input wire:model="email" id="email" type="email" name="email" required autofocus autocomplete="username" />
        </x-ui.field>

        <x-ui.field label="Password" name="password">
            <x-ui.input wire:model="password" id="password" type="password" name="password" required autocomplete="new-password" />
        </x-ui.field>

        <x-ui.field label="Confirm Password" name="password_confirmation">
            <x-ui.input wire:model="password_confirmation" id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
        </x-ui.field>

        <div class="flex items-center justify-end">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="resetPassword">
                {{ __('Reset Password') }}
            </x-ui.button>
        </div>
    </form>
</div>
