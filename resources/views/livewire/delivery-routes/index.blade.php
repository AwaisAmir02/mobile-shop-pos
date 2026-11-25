<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\DeliveryRoute;
use App\Models\User;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Routes (W8): a distributor's beats. Each has visiting days, a booker, and
 * its customers in the order they are visited, which is the order of the
 * stops on a load sheet.
 */
new #[Layout('layouts.app')] #[Title('Routes')] class extends Component
{
    use Toasts;

    #[Url(as: 'route', except: null)]
    public ?int $routeId = null;

    public ?int $editingId = null;
    public string $name = '';
    public array $visitDays = [];
    public string $bookerId = '';
    public bool $isActive = true;

    public string $addCustomerId = '';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->usesTool('routes'), 404);
    }

    public function openCreate(): void
    {
        $this->reset(['editingId', 'name', 'visitDays', 'bookerId']);
        $this->isActive = true;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'route-form');
    }

    public function openEdit(int $id): void
    {
        $route = DeliveryRoute::findOrFail($id);
        $this->editingId = $route->id;
        $this->name = $route->name;
        $this->visitDays = $route->visit_days ?? [];
        $this->bookerId = (string) ($route->booker_id ?? '');
        $this->isActive = $route->is_active;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'route-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100', \Illuminate\Validation\Rule::unique('delivery_routes', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->editingId)],
            'visitDays' => ['array'],
            'visitDays.*' => ['in:'.implode(',', array_keys(DeliveryRoute::DAYS))],
            'bookerId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('users', 'id')->where('shop_id', Auth::user()->shop_id)],
        ], [], ['bookerId' => 'booker']);

        $attributes = ['name' => $this->name, 'visit_days' => array_values($this->visitDays), 'booker_id' => $this->bookerId !== '' ? (int) $this->bookerId : null, 'is_active' => $this->isActive];
        $route = $this->editingId ? tap(DeliveryRoute::findOrFail($this->editingId))->update($attributes) : DeliveryRoute::create($attributes);

        $this->routeId = $route->id;
        $this->dispatch('close-modal', name: 'route-form');
        $this->toastSuccess('Route saved.');
    }

    public function addCustomer(): void
    {
        $route = DeliveryRoute::findOrFail($this->routeId);
        $customer = Customer::findOrFail((int) $this->addCustomerId);
        $customer->update(['route_id' => $route->id, 'route_sequence' => (int) Customer::where('route_id', $route->id)->max('route_sequence') + 1]);
        $this->addCustomerId = '';
    }

    public function removeCustomer(int $customerId): void
    {
        Customer::where('route_id', $this->routeId)->findOrFail($customerId)->update(['route_id' => null, 'route_sequence' => null]);
        $this->renumber();
    }

    /** Moves a stop one place earlier (-1) or later (+1) in the visiting order. */
    public function move(int $customerId, int $step): void
    {
        $ids = Customer::where('route_id', $this->routeId)->orderByRaw('route_sequence IS NULL')->orderBy('route_sequence')->orderBy('name')->pluck('id')->all();
        $at = array_search($customerId, $ids, true);
        $to = $at === false ? false : $at + $step;

        if ($at === false || $to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
        $this->renumber($ids);
    }

    protected function renumber(?array $ids = null): void
    {
        $ids ??= Customer::where('route_id', $this->routeId)->orderByRaw('route_sequence IS NULL')->orderBy('route_sequence')->orderBy('name')->pluck('id')->all();

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $i => $id) {
                Customer::whereKey($id)->update(['route_sequence' => $i + 1]);
            }
        });
    }

    public function with(): array
    {
        $route = $this->routeId ? DeliveryRoute::with('booker')->find($this->routeId) : null;

        return [
            'routes' => DeliveryRoute::query()->with('booker')->withCount('customers')->orderBy('name')->get(),
            'route' => $route,
            'stops' => $route ? $route->customers()->get() : collect(),
            'unassigned' => $route ? Customer::query()->whereNull('route_id')->orderBy('name')->limit(500)->get() : collect(),
            'users' => User::query()->where('shop_id', Auth::user()->shop_id)->orderBy('name')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('customers.index') }}" back-label="Customers" subtitle="Beats your bookers visit: the customers on each, in visiting order">Routes</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Routes" :padding="false">
            <x-slot name="actions">
                <x-ui.button size="sm" wire:click="openCreate" data-new-route>New Route</x-ui.button>
            </x-slot>
            @if ($routes->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-faint">No routes yet.</p>
            @else
                <ul class="divide-y divide-line">
                    @foreach ($routes as $item)
                        <li wire:key="route-{{ $item->id }}">
                            <button type="button" wire:click="$set('routeId', {{ $item->id }})" @class(['flex w-full items-center justify-between gap-3 px-5 py-3 text-left hover:bg-surface-sunken', 'bg-surface-sunken' => $routeId === $item->id])>
                                <span class="min-w-0">
                                    <span class="block text-sm font-semibold text-ink">{{ $item->name }}{{ $item->is_active ? '' : ' (paused)' }}</span>
                                    <span class="block text-xs text-ink-faint">{{ $item->daysLabel() }}{{ $item->booker ? ' · '.$item->booker->name : '' }}</span>
                                </span>
                                <span class="text-xs text-ink-faint">{{ $item->customers_count }} {{ \Illuminate\Support\Str::plural('stop', $item->customers_count) }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.card>

        <div class="lg:col-span-2">
            @if ($route)
                <x-ui.card :title="$route->name" :description="$route->daysLabel().($route->booker ? ' · booker '.$route->booker->name : '')" data-route-stops>
                    <x-slot name="actions">
                        <x-ui.button size="sm" variant="secondary" wire:click="openEdit({{ $route->id }})">Edit</x-ui.button>
                    </x-slot>
                    @if ($stops->isEmpty())
                        <p class="text-sm text-ink-faint">No customers on this route yet.</p>
                    @else
                        <ol class="divide-y divide-line">
                            @foreach ($stops as $i => $stop)
                                <li wire:key="stop-{{ $stop->id }}" class="flex items-center justify-between gap-3 py-2">
                                    <span class="min-w-0 text-sm">
                                        <span class="mr-2 tabular-nums text-ink-faint">{{ $i + 1 }}.</span>
                                        <span class="font-semibold text-ink">{{ $stop->displayName() }}</span>
                                        <span class="text-xs text-ink-faint">{{ collect([$stop->area, $stop->phone])->filter()->implode(' · ') }}</span>
                                    </span>
                                    <span class="flex shrink-0 gap-1">
                                        <x-ui.button size="sm" variant="secondary" wire:click="move({{ $stop->id }}, -1)" aria-label="Earlier">↑</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" wire:click="move({{ $stop->id }}, 1)" aria-label="Later">↓</x-ui.button>
                                        <x-ui.button size="sm" variant="danger-outline" wire:click="removeCustomer({{ $stop->id }})">Remove</x-ui.button>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                    <div class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4">
                        <x-ui.field label="Add a customer" for="addCustomer" class="w-full sm:w-80">
                            <x-ui.select wire:model="addCustomerId" id="addCustomer">
                                <option value="">Customers not on a route…</option>
                                @foreach ($unassigned as $customer)
                                    <option value="{{ $customer->id }}">{{ $customer->displayName() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.button wire:click="addCustomer" data-add-stop>Add to route</x-ui.button>
                    </div>
                </x-ui.card>
            @else
                <x-ui.empty-state title="Choose a route" description="Pick a route to see and arrange its customers, or make a new one." />
            @endif
        </div>
    </div>

    <x-ui.modal name="route-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-bold text-ink">{{ $editingId ? 'Edit Route' : 'New Route' }}</h2>
            <div class="mt-5 space-y-4">
                <x-ui.field label="Name" name="name" for="routeName">
                    <x-ui.input wire:model="name" id="routeName" maxlength="100" placeholder="e.g. Shah Alam - Monday" />
                </x-ui.field>
                <div>
                    <p class="text-sm font-medium text-ink">Visiting days</p>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach (\App\Models\DeliveryRoute::DAYS as $value => $label)
                            <x-ui.checkbox wire:model="visitDays" :value="$value" :label="$label" />
                        @endforeach
                    </div>
                </div>
                <x-ui.field label="Booker" name="bookerId" for="routeBooker" help="Who books orders on this route">
                    <x-ui.select wire:model="bookerId" id="routeBooker">
                        <option value="">None</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}">{{ $user->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.checkbox wire:model="isActive" label="In use" />
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'route-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
