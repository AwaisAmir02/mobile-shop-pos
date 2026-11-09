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
        $this->email = (string) Auth::user()->email;
    }

    public function updateProfileInformation(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [$user->phone ? 'nullable' : 'required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
        ]);

        $user->update(['name' => $validated['name'], 'email' => $validated['email'] ?: null]);

        $this->dispatch('profile-updated', name: $user->name);

        $this->toastSuccess('Profile updated.');
    }
}; ?>

<section>

    <form wire:submit="updateProfileInformation" class="space-y-5">
        <x-ui.field label="Name" name="name" required>
            <x-ui.input wire:model="name" id="name" name="name" type="text" required autofocus autocomplete="name" />
        </x-ui.field>

        <x-ui.field label="Email" name="email" :help="Auth::user()->phone ? 'Not needed: you log in with your mobile number' : null">
            <x-ui.input wire:model="email" id="email" name="email" type="email" :required="! Auth::user()->phone" autocomplete="username" />
        </x-ui.field>

        @if (Auth::user()->phone)
            <x-ui.field label="Mobile number">
                <p class="py-2.5 text-sm text-ink-muted">{{ \App\Support\PhoneNumber::display(Auth::user()->phone) }}{{ Auth::user()->phone_verified_at ? ' · verified' : '' }}</p>
            </x-ui.field>
        @endif

        <div class="flex items-center gap-4">
            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="updateProfileInformation">
                {{ __('Save') }}
            </x-ui.button>
        </div>
    </form>
</section>
