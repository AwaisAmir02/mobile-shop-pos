<x-app-layout>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">{{ __('Dashboard') }}</h1>
    </x-slot>

    <x-ui.card>
        <p class="text-sm text-slate-500">{{ __("You're logged in!") }}</p>
    </x-ui.card>
</x-app-layout>
