<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name') }}</title>

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
        <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-slate-900 antialiased">
        <div class="flex min-h-screen flex-col items-center justify-center bg-slate-50 px-4 py-10">
            <a href="/" wire:navigate class="flex items-center">
                <img
                    src="{{ asset(config('branding.logo_horizontal')) }}"
                    srcset="{{ asset(config('branding.logo_horizontal')) }} 1x, {{ asset(config('branding.logo_horizontal_2x')) }} 2x"
                    alt="{{ config('branding.name') }}"
                    class="h-10 w-auto"
                >
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
