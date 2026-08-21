<?php

use App\Livewire\Concerns\Toasts;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $current_password = '';
    public string $password = '';
    public string $password_confirmation = '';

    public function updatePassword(): void
    {
        try {
            $validated = $this->validate([
                'current_password' => ['required', 'string', 'current_password'],
                'password' => ['required', 'string', Password::defaults(), 'confirmed'],
            ]);
        } catch (ValidationException $e) {
            $this->reset('current_password', 'password', 'password_confirmation');

            throw $e;
        }

        Auth::user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        $this->reset('current_password', 'password', 'password_confirmation');

        $this->dispatch('password-updated');

        $this->toastSuccess('Password updated.');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-semibold text-slate-900">
            {{ __('Update Password') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500">
            {{ __('Ensure your account is using a long, random password to stay secure.') }}
        </p>
    </header>

    <form wire:submit="updatePassword" class="mt-6 space-y-6">
        <x-ui.field label="Current Password" name="current_password">
            <x-ui.input wire:model="current_password" id="update_password_current_password" name="current_password" type="password" autocomplete="current-password" />
        </x-ui.field>

        <x-ui.field label="New Password" name="password">
            <x-ui.input wire:model="password" id="update_password_password" name="password" type="password" autocomplete="new-password" />
        </x-ui.field>

        <x-ui.field label="Confirm Password" name="password_confirmation">
            <x-ui.input wire:model="password_confirmation" id="update_password_password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" />
        </x-ui.field>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="updatePassword">
                {{ __('Save') }}
            </x-ui.button>
        </div>
    </form>
</section>
