<?php

use App\Livewire\Concerns\Toasts;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';
    public string $email = '';

    public function mount(): void
    {
        $this->name = Auth::user()->name;
        $this->email = Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->update($validated);

        $this->dispatch('profile-updated', name: $user->name);

        $this->toastSuccess('Profile updated.');
    }
}; ?>

<section>
    <header>
        <h2 class="text-lg font-semibold text-slate-900">
            {{ __('Profile Information') }}
        </h2>

        <p class="mt-1 text-sm text-slate-500">
            {{ __("Update your account's profile information and email address.") }}
        </p>
    </header>

    <form wire:submit="updateProfileInformation" class="mt-6 space-y-6">
        <x-ui.field label="Name" name="name">
            <x-ui.input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" />
        </x-ui.field>

        <x-ui.field label="Email" name="email">
            <x-ui.input wire:model="email" id="email" name="email" type="email" required autocomplete="username" />
        </x-ui.field>

        <div class="flex items-center gap-4">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation">
                {{ __('Save') }}
            </x-ui.button>
        </div>
    </form>
</section>
