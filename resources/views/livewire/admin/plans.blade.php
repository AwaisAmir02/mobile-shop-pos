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
    public string $trialDays = '15';
    public bool $isActive = true;
    public bool $isFeatured = false;
    public array $limits = [];
    public array $featureFlags = [];

    /**
     * "Has a usage limit?" for each limit paired with a feature. Only a view
     * of $limits: it starts on when the limit has a number, and turning it
     * off clears the number, which saves as unlimited (null).
     */
    public array $limited = [];

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'tagline', 'price', 'isFeatured', 'trialDays']);
        $this->billingInterval = PlanType::Monthly->value;
        $this->isActive = true;
        $this->limits = array_fill_keys(array_column(LimitKey::cases(), 'value'), '');
        $this->featureFlags = array_fill_keys(array_column(FeatureKey::planCases(), 'value'), false);
        $this->limited = $this->pairedLimits()->mapWithKeys(fn (LimitKey $key) => [$key->value => false])->all();
    }

    protected function pairedLimits()
    {
        return collect(FeatureKey::planCases())->map(fn (FeatureKey $feature) => $feature->limit())->filter()->values();
    }

    public function updatedLimited($value, string $key): void
    {
        if (! $value) {
            $this->limits[$key] = '';
            $this->resetValidation('limits.'.$key);
        }
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
        $this->trialDays = (string) $plan->trial_days;
        $this->isActive = $plan->is_active;
        $this->isFeatured = $plan->is_featured;

        foreach (LimitKey::cases() as $key) {
            $value = $plan->limits[$key->value] ?? null;
            $this->limits[$key->value] = $value === null ? '' : (string) $value;
        }

        foreach (FeatureKey::planCases() as $key) {
            $this->featureFlags[$key->value] = (bool) ($plan->feature_flags[$key->value] ?? false);
        }

        foreach ($this->pairedLimits() as $key) {
            $this->limited[$key->value] = $this->limits[$key->value] !== '';
        }

        $this->dispatch('open-modal', name: 'plan-form');
    }

    /** The form as an unsaved plan, for the pricing preview. Anything not yet a valid number counts as unlimited. */
    protected function draftPlan(): Plan
    {
        return new Plan([
            'limits' => collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => is_numeric($this->limits[$key->value] ?? null) ? max(0, (int) $this->limits[$key->value]) : null])->all(),
            'feature_flags' => collect(FeatureKey::planCases())->mapWithKeys(fn (FeatureKey $key) => [$key->value => (bool) ($this->featureFlags[$key->value] ?? false)])->all(),
        ]);
    }

    /** What the pricing page will show for this plan, beside the other active plans. */
    protected function previewLines(): array
    {
        $draft = $this->draftPlan();
        $others = Plan::active()->when($this->editingId, fn ($query) => $query->whereKeyNot($this->editingId))->get();

        return $draft->pricingLines(Plan::offeredFeatures($others->push($draft)));
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'billingInterval' => ['required', Rule::enum(PlanType::class)],
            'trialDays' => ['required', 'integer', 'min:0', 'max:365'],
            ...collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => ['limits.'.$key->value => [
                ($this->limited[$key->value] ?? false) ? 'required' : 'nullable', 'integer', 'min:'.$key->minimum(), 'max:100000000',
            ]])->all(),
            'featureFlags.*' => ['boolean'],
        ], ['limits.*.required' => 'Enter a limit, or turn off “Has a usage limit?”.'], ['limits.*' => 'limit', 'trialDays' => 'free trial']);

        $data = [
            'name' => trim($this->name),
            'tagline' => trim($this->tagline) !== '' ? trim($this->tagline) : null,
            'price' => $this->price,
            'billing_interval' => $this->billingInterval,
            'trial_days' => (int) $this->trialDays,
            'is_active' => $this->isActive,
            'is_featured' => $this->isFeatured,
            'limits' => collect(LimitKey::cases())->mapWithKeys(fn (LimitKey $key) => [$key->value => ($this->limits[$key->value] ?? '') === '' ? null : (int) $this->limits[$key->value]])->all(),
            'feature_flags' => collect(FeatureKey::planCases())->mapWithKeys(fn (FeatureKey $key) => [$key->value => (bool) ($this->featureFlags[$key->value] ?? false)])->all(),
        ];

        if ($this->editingId) {
            $plan = Plan::findOrFail($this->editingId);
            $plan->update($data);
            app(Entitlements::class)->syncPlan($plan);
            $this->toastSuccess('Plan updated. Shops on this plan now use the new limits and features.');
        } else {
            // features: the old hand-typed pricing lines, no longer shown anywhere.
            Plan::create($data + ['features' => [], 'sort_order' => (int) Plan::max('sort_order') + 1]);
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
            'preview' => $this->previewLines(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Plans</x-ui.page-header>
    </x-slot>

    <x-ui.card title="Plans & pricing" description="Active plans appear on the public pricing page in this order. Deactivating a plan hides it there; shops already on it keep working.">
        <x-slot name="actions">
            <x-ui.button size="sm" wire:click="openCreate">New Plan</x-ui.button>
        </x-slot>

        @if ($plans->isEmpty())
            <x-ui.empty-state title="No plans yet" description="Create a plan to show it on the pricing page." />
        @else
            <x-ui.table stack :headers="['Order', 'Plan', 'Price', 'Features', 'Shops', 'Status', '']">
                @foreach ($plans as $plan)
                    <x-ui.table-row wire:key="plan-{{ $plan->id }}">
                        <x-ui.table-cell primary>
                            <div class="flex gap-1">
                                <button type="button" wire:click="move({{ $plan->id }}, 'up')" @disabled($loop->first) class="rounded p-1 text-ink-faint hover:bg-surface-sunken disabled:opacity-30" aria-label="Move {{ $plan->name }} up">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" wire:click="move({{ $plan->id }}, 'down')" @disabled($loop->last) class="rounded p-1 text-ink-faint hover:bg-surface-sunken disabled:opacity-30" aria-label="Move {{ $plan->name }} down">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Plan">
                            <div class="flex items-center gap-2 font-medium text-ink">
                                {{ $plan->name }}
                                @if ($plan->is_featured)
                                    <x-ui.badge variant="brand">Most popular</x-ui.badge>
                                @endif
                            </div>
                            @if ($plan->tagline)
                                <p class="text-xs text-ink-faint">{{ $plan->tagline }}</p>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Price" class="font-semibold text-ink">{{ $plan->priceLabel() }} <span class="font-normal text-ink-faint">/ {{ $plan->intervalLabel() }}</span>
                            <span class="block text-xs font-normal text-ink-faint">{{ $plan->trial_days > 0 ? $plan->trial_days.'-day free trial' : 'No free trial' }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Features">{{ count($plan->includedFeatures()) }} of {{ count(FeatureKey::planCases()) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Shops">{{ $plan->shops_count }}</x-ui.table-cell>
                        <x-ui.table-cell label="Status">
                            <x-ui.badge :variant="$plan->is_active ? 'success' : 'neutral'">{{ $plan->is_active ? 'Active' : 'Inactive' }}</x-ui.badge>
                        </x-ui.table-cell>
                        <x-ui.table-cell actions>
                            <x-ui.row-actions label="Actions for {{ $plan->name }}">
                                <x-ui.menu-item wire:click="openEdit({{ $plan->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-item wire:click="toggleActive({{ $plan->id }})">{{ $plan->is_active ? 'Deactivate' : 'Activate' }}</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="delete({{ $plan->id }})" confirm="Delete the {{ $plan->name }} plan permanently?" confirm-label="Delete">Delete</x-ui.menu-item>
                            </x-ui.row-actions>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="plan-form" max-width="xl">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-ink">{{ $editingId ? 'Edit Plan' : 'New Plan' }}</h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Name" name="name" for="planName">
                        <x-ui.input wire:model="name" id="planName" maxlength="80" />
                    </x-ui.field>
                    <x-ui.field label="Tagline" name="tagline" for="planTagline" help="One short line under the name">
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
                    <x-ui.field label="Free trial (days)" name="trialDays" for="planTrialDays" help="Full use of the plan from signup, before the first payment. 0 asks for payment straight away. Changing it affects new signups only.">
                        <x-ui.input wire:model="trialDays" id="planTrialDays" type="number" min="0" max="365" />
                    </x-ui.field>
                </div>

                <div class="flex flex-wrap gap-6">
                    <x-ui.checkbox wire:model="isActive" label="Show on pricing page" />
                    <x-ui.checkbox wire:model="isFeatured" label="Highlight as “Most popular”" />
                </div>

                <div class="rounded-lg border border-line p-4">
                    <p class="text-sm font-medium text-ink-muted">Core limits</p>
                    <p class="text-xs text-ink-faint">Leave blank for unlimited. Sales are never blocked — shops over the sales limit are only flagged.</p>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                        @foreach (LimitKey::core() as $limitKey)
                            <x-ui.field :label="$limitKey->label()" :name="'limits.'.$limitKey->value" :for="'limit-'.$limitKey->value">
                                <x-ui.input wire:model.live.debounce.400ms="limits.{{ $limitKey->value }}" id="limit-{{ $limitKey->value }}" type="number" :min="$limitKey->minimum()" placeholder="Unlimited" />
                            </x-ui.field>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-line p-4">
                    <p class="text-sm font-medium text-ink-muted">Features</p>
                    <p class="text-xs text-ink-faint">Ticked features are switched on for every shop on the plan; you can still override a single shop from its page. A usage limit counts whether or not its feature is ticked.</p>
                    <div class="mt-3 divide-y divide-line">
                        @foreach (FeatureKey::planCases() as $featureKey)
                            @php $pairedLimit = $featureKey->limit(); @endphp
                            <div class="py-2.5" wire:key="plan-feature-{{ $featureKey->value }}">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                    <x-ui.checkbox wire:model.live="featureFlags.{{ $featureKey->value }}" :label="$featureKey->label()" />
                                    @if ($pairedLimit)
                                        <div class="flex flex-wrap items-center gap-3">
                                            <x-ui.checkbox wire:model.live="limited.{{ $pairedLimit->value }}" label="Has a usage limit?" />
                                            @if ($limited[$pairedLimit->value] ?? false)
                                                <div class="flex items-center gap-2">
                                                    <x-ui.input wire:model.live.debounce.400ms="limits.{{ $pairedLimit->value }}" id="limit-{{ $pairedLimit->value }}" type="number" :min="$pairedLimit->minimum()" class="w-28" aria-label="{{ $pairedLimit->label() }}" />
                                                    <span class="text-xs text-ink-faint">{{ $pairedLimit->unit().($pairedLimit->isMonthly() ? ' a month' : '') }}</span>
                                                </div>
                                            @else
                                                <span class="text-xs text-ink-faint">Unlimited</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                @if ($pairedLimit)
                                    <x-ui.error :messages="$errors->get('limits.'.$pairedLimit->value)" class="mt-1" />
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rounded-lg border border-line bg-surface-sunken/60 p-4">
                    <p class="text-sm font-medium text-ink-muted">Pricing page preview</p>
                    <p class="text-xs text-ink-faint">Built from the limits and features above, so there's nothing else to keep in sync. A feature no active plan includes isn't listed.</p>
                    <ul class="mt-3 space-y-2 text-sm" data-plan-preview>
                        @foreach ($preview as $line)
                            <li @class(['flex gap-2.5', 'text-ink-faint' => ! $line['included'], 'text-ink' => $line['included']])>
                                @if ($line['included'])
                                    <svg class="mt-0.5 h-4 w-4 shrink-0 text-success" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
                                    <span>{{ $line['label'] }}@if ($line['detail'])<span class="text-ink-faint"> · {{ $line['detail'] }}</span>@endif</span>
                                @else
                                    <svg class="mt-0.5 h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                                    <span class="line-through"><span class="sr-only">Not included: </span>{{ $line['label'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'plan-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Plan' : 'Create Plan' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
