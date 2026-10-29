<?php

use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Enums\PlanType;
use App\Livewire\Concerns\Toasts;
use App\Models\Plan;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Plans')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;
    public string $name = '';
    public string $tagline = '';
    public string $price = '';
    public string $billingInterval = 'monthly';
    public bool $isActive = true;
    public bool $isFeatured = false;
    public array $features = [];
    public array $limits = [];
    public array $featureFlags = [];

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'tagline', 'price', 'isFeatured']);
        $this->billingInterval = PlanType::Monthly->value;
        $this->isActive = true;
        $this->features = [['label' => '', 'included' => true]];
        $this->limits = array_fill_keys(array_column(LimitKey::cases(), 'value'), '');
        $this->featureFlags = array_fill_keys(array_column(FeatureKey::cases(), 'value'), false);
    }

    public function openCreate(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();
        $this->dispatch('open-modal', name: 'plan-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();

        $plan = Plan::findOrFail($id);
        $this->editingId = $plan->id;
        $this->name = $plan->name;
        $this->tagline = (string) $plan->tagline;
        $this->price = (string) (float) $plan->price;
        $this->billingInterval = $plan->billing_interval->value;
        $this->isActive = $plan->is_active;
        $this->isFeatured = $plan->is_featured;
        $this->features = $plan->featureList() ?: [['label' => '', 'included' => true]];

        foreach (LimitKey::cases() as $key) {
            $value = $plan->limits[$key->value] ?? null;
            $this->limits[$key->value] = $value === null ? '' : (string) $value;
        }

        foreach (FeatureKey::cases() as $key) {
            $this->featureFlags[$key->value] = (bool) ($plan->feature_flags[$key->value] ?? false);
        }

        $this->dispatch('open-modal', name: 'plan-form');
    }

    public function addFeature(): void
    {
        if (count($this->features) < Plan::MAX_FEATURES) {
            $this->features[] = ['label' => '', 'included' => true];
        }
    }

    public function removeFeature(int $index): void
    {
        unset($this->features[$index]);
        $this->features = array_values($this->features);
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'billingInterval' => ['required', Rule::enum(PlanType::class)],
            'features' => ['array', 'max:'.Plan::MAX_FEATURES],
            'features.*.label' => ['nullable', 'string', 'max:120'],
            'features.*.included' => ['boolean'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'featureFlags.*' => ['boolean'],
        ], [], ['features.*.label' => 'feature', 'limits.*' => 'limit']);

        $features = collect($this->features)
            ->map(fn ($f) => ['label' => trim((string) ($f['label'] ?? '')), 'included' => (bool) ($f['included'] ?? false)])
            ->filter(fn ($f) => $f['label'] !== '')
            ->values()
            ->all();

        if ($features === []) {
            $this->addError('features', 'Add at least one feature line.');

            return;
        }

        $data = [
            'name' => trim($this->name),
            'tagline' => trim($this->tagline) !== '' ? trim($this->tagline) : null,
            'price' => $this->price,
            'billing_interval' => $this->billingInterval,
            'features' => $features,
            'is_active' => $this->isActive,
            'is_featured' => $this->isFeatured,
            'limits' => collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => ($this->limits[$key->value] ?? '') === '' ? null : (int) $this->limits[$key->value]])->all(),
            'feature_flags' => collect(FeatureKey::cases())->mapWithKeys(fn (FeatureKey $key) => [$key->value => (bool) ($this->featureFlags[$key->value] ?? false)])->all(),
        ];

        if ($this->editingId) {
            $plan = Plan::findOrFail($this->editingId);
            $plan->update($data);
            app(Entitlements::class)->syncPlan($plan);
            $this->toastSuccess('Plan updated. Shops on this plan now use the new limits and features.');
        } else {
            Plan::create($data + ['sort_order' => (int) Plan::max('sort_order') + 1]);
            $this->toastSuccess('Plan created.');
        }

        $this->dispatch('close-modal', name: 'plan-form');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeSuperAdmin();

        $plan = Plan::findOrFail($id);
        $plan->update(['is_active' => ! $plan->is_active]);

        $this->toastSuccess($plan->is_active ? 'Plan is now shown on the pricing page.' : 'Plan hidden from the pricing page. Shops already on it are not affected.');
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeSuperAdmin();

        DB::transaction(function () use ($id, $direction) {
            $ids = Plan::ordered()->lockForUpdate()->pluck('id')->all();
            $position = array_search($id, $ids, true);
            $target = $direction === 'up' ? $position - 1 : $position + 1;

            if ($position === false || ! isset($ids[$target])) {
                return;
            }

            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

            foreach ($ids as $index => $planId) {
                Plan::whereKey($planId)->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function delete(int $id): void
    {
        $this->authorizeSuperAdmin();

        $plan = Plan::withCount('shops')->findOrFail($id);

        if ($plan->shops_count > 0) {
            $this->toastError($plan->shops_count.' '.str('shop')->plural($plan->shops_count).' '.($plan->shops_count === 1 ? 'is' : 'are').' on this plan. Deactivate it instead — they keep working.');

            return;
        }

        $plan->delete();
        $this->toastSuccess('Plan deleted.');
    }

    public function with(): array
    {
        return [
            'plans' => Plan::ordered()->withCount('shops')->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Plans</h1>
    </x-slot>

    <x-ui.card title="Plans & pricing" description="Active plans appear on the public pricing page in this order. Deactivating a plan hides it there; shops already on it keep working.">
        <x-slot name="actions">
            <x-ui.button size="sm" wire:click="openCreate">New Plan</x-ui.button>
        </x-slot>

        @if ($plans->isEmpty())
            <x-ui.empty-state title="No plans yet" description="Create a plan to show it on the pricing page." />
        @else
            <x-ui.table :headers="['Order', 'Plan', 'Price', 'Features', 'Shops', 'Status', '']">
                @foreach ($plans as $plan)
                    <x-ui.table-row wire:key="plan-{{ $plan->id }}">
                        <x-ui.table-cell>
                            <div class="flex gap-1">
                                <button type="button" wire:click="move({{ $plan->id }}, 'up')" @disabled($loop->first) class="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" aria-label="Move {{ $plan->name }} up">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" wire:click="move({{ $plan->id }}, 'down')" @disabled($loop->last) class="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" aria-label="Move {{ $plan->name }} down">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            <div class="flex items-center gap-2 font-medium text-slate-900">
                                {{ $plan->name }}
                                @if ($plan->is_featured)
                                    <x-ui.badge variant="brand">Most popular</x-ui.badge>
                                @endif
                            </div>
                            @if ($plan->tagline)
                                <p class="text-xs text-slate-500">{{ $plan->tagline }}</p>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">{{ $plan->priceLabel() }} <span class="font-normal text-slate-500">/ {{ $plan->intervalLabel() }}</span></x-ui.table-cell>
                        <x-ui.table-cell>{{ collect($plan->featureList())->where('included', true)->count() }} included · {{ collect($plan->featureList())->where('included', false)->count() }} not</x-ui.table-cell>
                        <x-ui.table-cell>{{ $plan->shops_count }}</x-ui.table-cell>
                        <x-ui.table-cell>
                            <x-ui.badge :variant="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            <div class="flex justify-end gap-1">
                                <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $plan->id }})">Edit</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $plan->id }})">{{ $plan->is_active ? 'Deactivate' : 'Activate' }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="delete({{ $plan->id }})" wire:confirm="Delete the {{ $plan->name }} plan permanently?">Delete</x-ui.button>
                            </div>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="plan-form" max-width="xl">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Plan' : 'New Plan' }}</h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Name" name="name" for="planName">
                        <x-ui.input wire:model="name" id="planName" maxlength="80" />
                    </x-ui.field>
                    <x-ui.field label="Tagline" name="tagline" for="planTagline" help="Optional — one short line under the name">
                        <x-ui.input wire:model="tagline" id="planTagline" maxlength="160" />
                    </x-ui.field>
                    <x-ui.field label="Price (Rs)" name="price" for="planPrice">
                        <x-ui.input wire:model="price" id="planPrice" type="number" min="0" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="Billed" name="billingInterval" for="planInterval">
                        <x-ui.select wire:model="billingInterval" id="planInterval">
                            @foreach (PlanType::cases() as $interval)
                                <option value="{{ $interval->value }}">{{ $interval->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                </div>

                <div class="flex flex-wrap gap-6">
                    <x-ui.checkbox wire:model="isActive" label="Show on pricing page" />
                    <x-ui.checkbox wire:model="isFeatured" label="Highlight as “Most popular”" />
                </div>

                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-sm font-medium text-slate-700">Usage limits</p>
                    <p class="text-xs text-slate-500">Leave blank for unlimited. Sales are never blocked — shops over the sales limit are only flagged.</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach (LimitKey::cases() as $limitKey)
                            <x-ui.field :label="$limitKey->label()" :name="'limits.'.$limitKey->value" :for="'limit-'.$limitKey->value">
                                <x-ui.input wire:model="limits.{{ $limitKey->value }}" id="limit-{{ $limitKey->value }}" type="number" min="0" placeholder="Unlimited" />
                            </x-ui.field>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200 p-4">
                    <p class="text-sm font-medium text-slate-700">Features this plan switches on</p>
                    <p class="text-xs text-slate-500">Applied to every shop on the plan. You can still override a single shop from its page.</p>
                    <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach (FeatureKey::cases() as $featureKey)
                            <x-ui.checkbox wire:model="featureFlags.{{ $featureKey->value }}" :label="$featureKey->label()" />
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between">
                        <p class="text-sm font-medium text-slate-700">Pricing page lines</p>
                        <p class="text-xs text-slate-500">Tick = included, untick = shown as not included</p>
                    </div>
                    <div class="mt-2 space-y-2">
                        @foreach ($features as $index => $feature)
                            <div class="flex items-center gap-3" wire:key="feature-{{ $index }}">
                                <input type="checkbox" wire:model="features.{{ $index }}.included" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500" aria-label="Feature {{ $index + 1 }} included">
                                <x-ui.input wire:model="features.{{ $index }}.label" placeholder="e.g. WhatsApp chatbot ordering" maxlength="120" class="flex-1" aria-label="Feature {{ $index + 1 }}" />
                                <button type="button" wire:click="removeFeature({{ $index }})" class="rounded p-1.5 text-slate-400 hover:bg-slate-100 hover:text-red-600" aria-label="Remove feature {{ $index + 1 }}">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                            <x-ui.error :messages="$errors->get('features.'.$index.'.label')" />
                        @endforeach
                    </div>
                    <x-ui.error :messages="$errors->get('features')" class="mt-2" />
                    @if (count($features) < Plan::MAX_FEATURES)
                        <x-ui.button type="button" size="sm" variant="secondary" wire:click="addFeature" class="mt-3">Add feature</x-ui.button>
                    @endif
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'plan-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Plan' : 'Create Plan' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
