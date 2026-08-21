<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('Profile') }}</h1>
    </x-slot>

    <div class="mx-auto max-w-2xl space-y-6">
        <x-ui.card>
            <livewire:profile.update-profile-information-form />
        </x-ui.card>

        <x-ui.card>
            <livewire:profile.update-password-form />
        </x-ui.card>

        <x-ui.card>
            <livewire:profile.delete-user-form />
        </x-ui.card>
    </div>
</x-app-layout>
