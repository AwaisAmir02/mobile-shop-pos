<?php

use App\Livewire\Concerns\Toasts;
use App\Services\BusinessCategories;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public function mount(): void
    {
        abort_unless(Auth::user()->is_owner, 403);
    }

    public function toggle(string $module, BusinessCategories $categories): void
    {
        abort_unless(Auth::user()->is_owner, 403);
        abort_unless(array_key_exists($module, BusinessCategories::modules()), 404);

        $shop = Auth::user()->shop;
        $hidden = BusinessCategories::hiddenModules($shop);
        $nowHidden = ! in_array($module, $hidden, true);

        $categories->setHiddenModules($shop, $nowHidden ? [...$hidden, $module] : array_values(array_diff($hidden, [$module])));

        $label = BusinessCategories::modules()[$module]['label'];
        $this->toastSuccess($nowHidden ? "{$label} hidden from your menu." : "{$label} is now in your menu.");
    }

    public function with(): array
    {
        $shop = Auth::user()->shop->fresh();

        return [
            'modules' => BusinessCategories::modules(),
            'hidden' => BusinessCategories::hiddenModules($shop),
            'shop' => $shop,
        ];
    }
}; ?>

<x-ui.card title="Modules" description="Show only the screens your shop uses. Hiding a module never deletes its records — turn it back on any time and everything is still there.">
    @if ($shop->business_category)
        <p class="mb-4 text-sm text-slate-500">Your shop is set up as <span class="font-medium text-slate-700">{{ $shop->business_category->label() }}</span>.</p>
    @endif

    <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200">
        @foreach ($modules as $key => $module)
            @php($blocked = $shop->isScreenDisabled($module['screens'][0]))
            @php($visible = ! in_array($key, $hidden, true))
            <li class="flex items-center justify-between gap-4 px-4 py-3" wire:key="module-{{ $key }}">
                <div>
                    <p class="text-sm font-medium text-slate-900">{{ $module['label'] }}</p>
                    <p class="text-xs text-slate-500">{{ $blocked ? 'Turned off for your shop by HisabOKitab — contact support to use it.' : $module['description'] }}</p>
                </div>
                @unless ($blocked)
                    <button
                        type="button"
                        role="switch"
                        aria-checked="{{ $visible ? 'true' : 'false' }}"
                        aria-label="{{ $visible ? 'Hide' : 'Show' }} {{ $module['label'] }}"
                        wire:click="toggle('{{ $key }}')"
                        @class([
                            'relative inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors',
                            'bg-emerald-500' => $visible,
                            'bg-slate-300' => ! $visible,
                        ])
                    >
                        <span @class(['inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform', 'translate-x-6' => $visible, 'translate-x-1' => ! $visible])></span>
                    </button>
                @endunless
            </li>
        @endforeach
    </ul>
</x-ui.card>
