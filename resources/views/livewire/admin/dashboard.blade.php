<?php

use App\Models\Shop;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Shops')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'shops' => Shop::query()
                ->withCount(['products', 'users'])
                ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
                ->latest()
                ->paginate(10),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Shops</h1>
            <a href="{{ route('admin.shops.create') }}" wire:navigate>
                <x-ui.button>
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Create Shop
                </x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4">
        <x-ui.input wire:model.live.debounce.400ms="search" type="search" placeholder="Search shops…" class="sm:max-w-xs" />
    </div>

    @if ($shops->isEmpty())
        <x-ui.empty-state
            title="No shops yet"
            description="Create your first shop to onboard a client."
        >
            <x-slot name="action">
                <a href="{{ route('admin.shops.create') }}" wire:navigate>
                    <x-ui.button>Create Shop</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Shop', 'Plan', 'Status', 'Start Date', 'Renewal', 'Products', 'Users', '']">
            @foreach ($shops as $shop)
                <x-ui.table-row wire:key="shop-{{ $shop->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        <a href="{{ route('admin.shops.show', $shop) }}" wire:navigate class="hover:text-brand-700">
                            {{ $shop->name }}
                        </a>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $shop->plan_type?->label() ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($shop->isSubscriptionActive())
                            <x-ui.badge variant="success">Active</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Inactive</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $shop->subscription_start_date?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $shop->expectedRenewalDate()?->format('d M Y') ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $shop->products_count }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $shop->users_count }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <a href="{{ route('admin.shops.show', $shop) }}" wire:navigate>
                            <x-ui.button size="sm" variant="ghost">View</x-ui.button>
                        </a>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $shops->links() }}
        </div>
    @endif
</div>
