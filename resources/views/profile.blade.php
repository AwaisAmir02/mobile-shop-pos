<x-app-layout>
    <x-slot name="header">
        <x-ui.page-header subtitle="Your own login, separate from the shop's settings">{{ __('Profile') }}</x-ui.page-header>
    </x-slot>

    <x-profile.sections class="mx-auto max-w-5xl" />
</x-app-layout>
