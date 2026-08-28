<?php

use App\Enums\ShopScreen;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Welcome')] class extends Component
{
    public function mount(): void
    {
        if (Auth::user()->hasAccessTo(ShopScreen::Dashboard)) {
            $this->redirectRoute('dashboard', navigate: true);
        }
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Welcome</h1>
    </x-slot>

    <div class="mx-auto max-w-lg">
        <x-ui.card>
            <div class="flex flex-col items-center py-6 text-center">
                <div class="flex h-14 w-14 items-center justify-center rounded-full bg-brand-100 text-lg font-semibold text-brand-700">
                    {{ mb_strtoupper(mb_substr(Auth::user()->name, 0, 1)) }}
                </div>

                <h2 class="mt-4 text-lg font-semibold text-slate-900">
                    Welcome, {{ Auth::user()->name }}
                </h2>

                <p class="mt-1 text-sm text-slate-500">
                    {{ Auth::user()->is_owner ? 'Owner' : (Auth::user()->role?->name ?? 'No role assigned') }}
                </p>

                <p class="mt-4 max-w-sm text-sm text-slate-500">
                    You don't currently have access to a Dashboard. Use the sidebar to reach the screens available to you, or contact your shop owner if you think this is a mistake.
                </p>
            </div>
        </x-ui.card>
    </div>
</div>
