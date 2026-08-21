<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-10">
            <a href="/" wire:navigate class="flex items-center gap-2.5">
                <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-600 text-white">
                    <x-application-logo class="h-5 w-5" />
                </span>
                <span class="text-lg font-semibold text-slate-900">{{ config('app.name') }}</span>
            </a>

            <div class="mt-6 w-full sm:max-w-md">
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white px-6 py-8 shadow-card">
                    {{ $slot }}
                </div>
            </div>
        </div>

        <x-ui.toast-container />
    </body>
</html>
