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
            class="hidden items-center gap-1.5 rounded-full bg-sale px-4 py-2 text-sm font-bold text-sale-ink shadow-soft transition-colors hover:bg-sale-hover focus-visible:ring-2 focus-visible:ring-link focus-visible:ring-offset-2 sm:inline-flex"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            New Sale
        </a>

        <span class="mx-1 hidden h-6 w-px bg-line sm:block"></span>
    @endif

    <div x-data="{ open: false }" class="relative shrink-0">
        <button
            x-on:click="open = ! open"
            x-on:click.outside="open = false"
            type="button"
            class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2.5 text-sm font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink"
        >
            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-soft text-sm font-bold text-on-primary-soft">
                {{ Str::upper(Str::substr($user->name, 0, 1)) }}
            </span>
            <span class="hidden max-w-[10rem] truncate md:block" x-data="{{ json_encode(['name' => $user->name]) }}" x-text="name" x-on:profile-updated.window="name = $event.detail.name"></span>
            <svg class="hidden h-4 w-4 text-ink-faint md:block" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5" />
            </svg>
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition.duration.100ms
            class="absolute right-0 z-40 mt-2 w-52 rounded-xl border border-line bg-surface-raised p-1.5 shadow-float"
        >
            <a href="{{ route('profile') }}" wire:navigate class="block rounded-lg px-3 py-2 text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink">
                {{ __('Profile') }}
            </a>

            <button wire:click="logout" class="block w-full rounded-lg px-3 py-2 text-left text-sm text-ink-muted hover:bg-surface-sunken hover:text-ink">
                {{ __('Log Out') }}
            </button>
        </div>
    </div>
</div>
