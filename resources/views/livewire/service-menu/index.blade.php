<?php

use App\Enums\MembershipKind;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\Toasts;
use App\Models\MembershipPlan;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/**
 * Service Menu (Services group): what the business sells besides goods.
 * Services have a price and a time (the time sizes a booking); a class is a
 * service taking more than one person at once. Memberships run by time,
 * packages by sessions. Selling either is a line on the bill (ServiceLines).
 */
new #[Layout('layouts.app')] #[Title('Service Menu')] class extends Component
{
    use Toasts;

    public ?int $serviceId = null;
    public string $serviceName = '';
    public string $serviceCategoryId = '';
    public string $newCategory = '';
    public string $servicePrice = '';
    public string $serviceDuration = '30';
    public string $serviceCapacity = '1';
    public bool $serviceActive = true;

    public ?int $planId = null;
    public string $planName = '';
    public string $planKind = 'time';
    public string $planPrice = '';
    public string $planDurationValue = '1';
    public string $planDurationUnit = 'months';
    public string $planSessions = '';
    public array $planServiceIds = [];
    public string $planMaxFreeze = '0';
    public bool $planActive = true;

    public function openService(?int $id = null): void
    {
        $this->resetErrorBag();
        $service = $id ? ServiceItem::findOrFail($id) : null;
        $this->serviceId = $service?->id;
        $this->serviceName = $service?->name ?? '';
        $this->serviceCategoryId = (string) ($service?->service_category_id ?? '');
        $this->newCategory = '';
        $this->servicePrice = $service?->price !== null ? (string) (float) $service->price : '';
        $this->serviceDuration = (string) ($service?->duration_minutes ?? 30);
        $this->serviceCapacity = (string) ($service?->capacity ?? 1);
        $this->serviceActive = $service?->is_active ?? true;
        $this->dispatch('open-modal', name: 'service-form');
    }

    public function saveService(): void
    {
        $shopId = Auth::user()->shop_id;
        $this->validate([
            'serviceName' => ['required', 'string', 'max:255'],
            'serviceCategoryId' => [$this->newCategory === '' ? 'required' : 'nullable', 'nullable', Rule::exists('service_categories', 'id')->where('shop_id', $shopId)],
            'newCategory' => ['nullable', 'string', 'max:100'],
            'servicePrice' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'serviceDuration' => ['required', 'integer', 'min:5', 'max:720'],
            'serviceCapacity' => ['required', 'integer', 'min:1', 'max:200'],
        ], ['serviceCategoryId.required' => 'Choose a category or type a new one.'], ['serviceName' => 'name', 'servicePrice' => 'price', 'serviceDuration' => 'time', 'serviceCapacity' => 'places']);

        $categoryId = $this->newCategory !== '' ? ServiceCategory::firstOrCreate(['name' => trim($this->newCategory)])->id : (int) $this->serviceCategoryId;
        $attributes = ['name' => trim($this->serviceName), 'service_category_id' => $categoryId, 'price' => $this->servicePrice, 'duration_minutes' => (int) $this->serviceDuration, 'capacity' => (int) $this->serviceCapacity, 'is_active' => $this->serviceActive];
        $this->serviceId ? ServiceItem::findOrFail($this->serviceId)->update($attributes) : ServiceItem::create($attributes);

        $this->dispatch('close-modal', name: 'service-form');
        $this->toastSuccess('Service saved.');
    }

    public function openPlan(?int $id = null): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Memberships), 404);
        $this->resetErrorBag();
        $plan = $id ? MembershipPlan::findOrFail($id) : null;
        $this->planId = $plan?->id;
        $this->planName = $plan?->name ?? '';
        $this->planKind = $plan?->kind->value ?? 'time';
        $this->planPrice = $plan ? (string) (float) $plan->price : '';
        $this->planDurationValue = (string) ($plan?->duration_value ?? ($plan ? '' : '1'));
        $this->planDurationUnit = $plan?->duration_unit ?? 'months';
        $this->planSessions = (string) ($plan?->sessions ?? '');
        $this->planServiceIds = array_map('strval', $plan?->service_item_ids ?? []);
        $this->planMaxFreeze = (string) ($plan?->max_freeze_days ?? 0);
        $this->planActive = $plan?->is_active ?? true;
        $this->dispatch('open-modal', name: 'plan-form');
    }

    public function savePlan(): void
    {
        abort_unless(Auth::user()->shop->usesTool(ServiceTradeTool::Memberships), 404);
        $sessions = $this->planKind === MembershipKind::Sessions->value;
        $this->validate([
            'planName' => ['required', 'string', 'max:100'],
            'planKind' => ['required', Rule::enum(MembershipKind::class)],
            'planPrice' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'planDurationValue' => [$sessions ? 'nullable' : 'required', 'nullable', 'integer', 'min:1', 'max:3650'],
            'planDurationUnit' => ['required', Rule::in(array_keys(MembershipPlan::UNITS))],
            'planSessions' => [$sessions ? 'required' : 'nullable', 'nullable', 'integer', 'min:1', 'max:1000'],
            'planServiceIds.*' => ['integer', Rule::exists('service_items', 'id')->where('shop_id', Auth::user()->shop_id)],
            'planMaxFreeze' => ['required', 'integer', 'min:0', 'max:365'],
        ], [], ['planName' => 'name', 'planPrice' => 'price', 'planDurationValue' => 'length', 'planSessions' => 'sessions', 'planMaxFreeze' => 'freeze days']);

        $attributes = [
            'name' => trim($this->planName),
            'kind' => $this->planKind,
            'price' => $this->planPrice,
            'duration_value' => $this->planDurationValue !== '' ? (int) $this->planDurationValue : null,
            'duration_unit' => $this->planDurationUnit,
            'sessions' => $sessions ? (int) $this->planSessions : null,
            'service_item_ids' => $sessions && $this->planServiceIds !== [] ? array_map('intval', $this->planServiceIds) : null,
            'max_freeze_days' => $sessions ? 0 : (int) $this->planMaxFreeze,
            'is_active' => $this->planActive,
        ];
        $this->planId ? MembershipPlan::findOrFail($this->planId)->update($attributes) : MembershipPlan::create($attributes);

        $this->dispatch('close-modal', name: 'plan-form');
        $this->toastSuccess('Saved.');
    }

    public function with(): array
    {
        return [
            'categories' => ServiceCategory::query()->orderBy('name')->get(),
            'services' => ServiceItem::query()->with('serviceCategory')->orderBy('name')->get(),
            'plans' => MembershipPlan::query()->orderBy('name')->get(),
            'membershipsOn' => Auth::user()->shop->usesTool(ServiceTradeTool::Memberships),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Your services with their prices and times, and the memberships and packages you sell">Service Menu</x-ui.page-header>
    </x-slot>

    <div class="space-y-6">
        <x-ui.card title="Services" :padding="false" data-services>
            <x-slot name="actions"><x-ui.button size="sm" wire:click="openService" data-new-service>New Service</x-ui.button></x-slot>
            @if ($services->isEmpty())
                <p class="px-5 py-8 text-center text-sm text-ink-faint">No services yet. Add what you do, with its price and how long it takes.</p>
            @else
                <x-ui.table :headers="['Service', 'Category', 'Price', 'Time', '']">
                    @foreach ($services as $service)
                        <tr wire:key="service-{{ $service->id }}">
                            <td class="px-4 py-2.5 text-sm font-semibold text-ink">{{ $service->name }}@if (! $service->is_active) <x-ui.badge>Off</x-ui.badge>@endif @if ($service->capacity > 1) <x-ui.badge variant="brand">Class · {{ $service->capacity }} places</x-ui.badge>@endif</td>
                            <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $service->serviceCategory?->name }}</td>
                            <td class="px-4 py-2.5 text-sm tabular-nums text-ink">{{ $service->price !== null ? 'Rs '.number_format((float) $service->price, 2) : '—' }}</td>
                            <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $service->duration_minutes ? $service->duration_minutes.' min' : '—' }}</td>
                            <td class="px-4 py-2.5 text-right"><x-ui.button size="sm" variant="secondary" wire:click="openService({{ $service->id }})">Edit</x-ui.button></td>
                        </tr>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        @if ($membershipsOn)
            <x-ui.card title="Memberships & packages" description="A membership runs for a time (a month at the gym); a package is a number of sessions (10 facials). Both are sold on the bill." :padding="false" data-plans>
                <x-slot name="actions"><x-ui.button size="sm" wire:click="openPlan" data-new-plan>New</x-ui.button></x-slot>
                @if ($plans->isEmpty())
                    <p class="px-5 py-8 text-center text-sm text-ink-faint">None yet.</p>
                @else
                    <x-ui.table :headers="['Name', 'Kind', 'Term', 'Price', '']">
                        @foreach ($plans as $plan)
                            <tr wire:key="plan-{{ $plan->id }}">
                                <td class="px-4 py-2.5 text-sm font-semibold text-ink">{{ $plan->name }}@if (! $plan->is_active) <x-ui.badge>Off</x-ui.badge>@endif</td>
                                <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $plan->kind->label() }}</td>
                                <td class="px-4 py-2.5 text-sm text-ink-muted">{{ $plan->termLabel() }}{{ $plan->max_freeze_days > 0 ? ' · freeze up to '.$plan->max_freeze_days.' days' : '' }}</td>
                                <td class="px-4 py-2.5 text-sm tabular-nums text-ink">Rs {{ number_format((float) $plan->price, 2) }}</td>
                                <td class="px-4 py-2.5 text-right"><x-ui.button size="sm" variant="secondary" wire:click="openPlan({{ $plan->id }})">Edit</x-ui.button></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endif
    </div>

    <x-ui.modal name="service-form" max-width="md">
        <form wire:submit="saveService" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">{{ $serviceId ? 'Edit Service' : 'New Service' }}</h2>
            <x-ui.field label="Name" name="serviceName" for="serviceName"><x-ui.input wire:model="serviceName" id="serviceName" placeholder="e.g. Haircut, Facial, Personal training" /></x-ui.field>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Category" name="serviceCategoryId" for="serviceCategoryId">
                    <x-ui.select wire:model="serviceCategoryId" id="serviceCategoryId">
                        <option value="">Choose…</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="…or a new category" name="newCategory" for="newCategory"><x-ui.input wire:model="newCategory" id="newCategory" /></x-ui.field>
                <x-ui.field label="Price (Rs)" name="servicePrice" for="servicePrice"><x-ui.input wire:model="servicePrice" id="servicePrice" type="number" min="0" step="0.01" /></x-ui.field>
                <x-ui.field label="Time (minutes)" name="serviceDuration" for="serviceDuration"><x-ui.input wire:model="serviceDuration" id="serviceDuration" type="number" min="5" step="5" /></x-ui.field>
                <x-ui.field label="Places" name="serviceCapacity" for="serviceCapacity" help="More than 1 for a class"><x-ui.input wire:model="serviceCapacity" id="serviceCapacity" type="number" min="1" /></x-ui.field>
            </div>
            <x-ui.checkbox wire:model="serviceActive" label="On the menu" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'service-form' })">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="plan-form" max-width="md">
        <form wire:submit="savePlan" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">{{ $planId ? 'Edit' : 'New membership or package' }}</h2>
            <x-ui.segmented name="planKind" wire:model.live="planKind" :options="['time' => 'Membership (by time)', 'sessions' => 'Package (by sessions)']" label="Kind" />
            <x-ui.field label="Name" name="planName" for="planName"><x-ui.input wire:model="planName" id="planName" placeholder="{{ $planKind === 'sessions' ? 'e.g. 10 Facials' : 'e.g. Monthly, Quarterly' }}" /></x-ui.field>
            <div class="grid grid-cols-2 gap-3">
                <x-ui.field label="Price (Rs)" name="planPrice" for="planPrice"><x-ui.input wire:model="planPrice" id="planPrice" type="number" min="0" step="0.01" /></x-ui.field>
                @if ($planKind === 'sessions')
                    <x-ui.field label="Sessions" name="planSessions" for="planSessions"><x-ui.input wire:model="planSessions" id="planSessions" type="number" min="1" /></x-ui.field>
                @endif
                <x-ui.field :label="$planKind === 'sessions' ? 'Use within (blank: no limit)' : 'Lasts'" name="planDurationValue" for="planDurationValue"><x-ui.input wire:model="planDurationValue" id="planDurationValue" type="number" min="1" /></x-ui.field>
                <x-ui.field label="Unit" name="planDurationUnit" for="planDurationUnit">
                    <x-ui.select wire:model="planDurationUnit" id="planDurationUnit">
                        @foreach (\App\Models\MembershipPlan::UNITS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                @if ($planKind === 'time')
                    <x-ui.field label="Can freeze up to (days)" name="planMaxFreeze" for="planMaxFreeze" help="0: can't be frozen"><x-ui.input wire:model="planMaxFreeze" id="planMaxFreeze" type="number" min="0" /></x-ui.field>
                @endif
            </div>
            @if ($planKind === 'sessions')
                <div>
                    <p class="text-sm font-medium text-ink">Sessions can be used on (none ticked: any service)</p>
                    <div class="mt-2 grid max-h-40 grid-cols-2 gap-2 overflow-y-auto">
                        @foreach ($services as $service)
                            <x-ui.checkbox wire:model="planServiceIds" :value="(string) $service->id" :label="$service->name" />
                        @endforeach
                    </div>
                </div>
            @endif
            <x-ui.checkbox wire:model="planActive" label="On sale" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'plan-form' })">Cancel</x-ui.button><x-ui.button type="submit">Save</x-ui.button></div>
        </form>
    </x-ui.modal>
</div>
