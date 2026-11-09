<?php

use App\Livewire\Actions\Logout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;

new class extends Component
{
    /** D14: whether this screen has a guide. Worked out by the layout on page load and kept across re-renders. */
    public bool $hasGuide = false;

    /** A new or removed profile photo shows straight away; re-rendering is enough. */
    #[On('avatar-updated')]
    public function refreshAvatar(): void
    {
    }

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

    @if ($hasGuide)
        <button
            type="button"
            x-data
            x-on:click="$dispatch('open-guide')"
            aria-label="Guide for this screen"
            title="Guide for this screen"
            data-guide-button
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-line bg-surface text-ink-muted shadow-soft transition-colors hover:border-line-strong hover:text-ink focus-visible:ring-2 focus-visible:ring-link"
        >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M12 17.25h.008v.008H12v-.008z" />
            </svg>
        </button>
    @endif

    <div x-data="{ open: false }" class="relative shrink-0">
        <button
            x-on:click="open = ! open"
            x-on:click.outside="open = false"
            type="button"
            class="flex items-center gap-2 rounded-full py-1 pl-1 pr-2.5 text-sm font-medium text-ink-muted hover:bg-surface-sunken hover:text-ink"
        >
            <x-ui.avatar :user="$user" size="h-8 w-8 text-sm" />
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
