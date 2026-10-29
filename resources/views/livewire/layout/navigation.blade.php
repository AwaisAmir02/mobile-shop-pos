<?php

use App\Livewire\Actions\Logout;
use Livewire\Volt\Component;

new class extends Component
{
    public function logout(Logout $logout): void
    {
        $logout();

        $this->redirect(route('login', absolute: false), navigate: true);
    }
}; ?>

@php
    $user = auth()->user();
    $showNewSale = ! $user->is_super_admin
        && $user->hasAccessTo('sales')
        && Route::has('sales.index')
        && ! request()->routeIs('sales.*');
@endphp

<div class="flex shrink-0 items-center gap-2">
    @if ($showNewSale)
        <a
            href="{{ route('sales.index') }}"
            wire:navigate
            class="hidden items-center gap-1.5 rounded-md bg-accent-500 px-3 py-1.5 text-sm font-semibold text-white shadow-sm transition hover:bg-accent-600 sm:inline-flex"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            New Sale
        </a>

        <span class="mx-1 hidden h-6 w-px bg-slate-200 sm:block"></span>
    @endif

    <div x-data="{ open: false }" class="relative shrink-0">
        <button
            x-on:click="open = ! open"
            x-on:click.outside="open = false"
            type="button"
            class="flex items-center gap-2 rounded-lg py-1 pl-1 pr-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
        >
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">
                {{ Str::upper(Str::substr($user->name, 0, 1)) }}
            </span>
            <span class="hidden max-w-[10rem] truncate md:block" x-data="{{ json_encode(['name' => $user->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
            <svg class="hidden h-4 w-4 text-slate-400 md:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.duration.100ms
            class="absolute right-0 z-40 mt-2 w-48 rounded-xl border border-slate-200 bg-white py-1.5 shadow-lg"
        >
            <a href="{{ route('profile') }}" wire:navigate class="block px-4 py-2 text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                {{ __('Profile') }}
            </a>

            <button wire:click="logout" class="block w-full px-4 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 hover:text-slate-900">
                {{ __('Log Out') }}
            </button>
        </div>
    </div>
</div>
