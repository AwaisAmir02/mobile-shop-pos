<?php

use App\Enums\AddonBilling;
use App\Enums\AddonKind;
use App\Enums\FeatureKey;
use App\Enums\LimitKey;
use App\Livewire\Concerns\Toasts;
use App\Models\Addon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Add-ons')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;
    public string $name = '';
    public string $description = '';
    public string $kind = 'limit_pack';
    public string $limitKey = 'products';
    public string $quantity = '';
    public string $featureKey = 'whatsapp';
    public string $price = '';
    public string $billing = 'monthly';
    public bool $isActive = true;

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function resetForm(): void
    {
        $this->resetValidation();
        $this->reset(['editingId', 'name', 'description', 'kind', 'limitKey', 'quantity', 'featureKey', 'price', 'billing', 'isActive']);
    }

    public function openCreate(): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();
        $this->dispatch('open-modal', name: 'addon-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeSuperAdmin();
        $this->resetForm();

        $addon = Addon::findOrFail($id);
        $this->editingId = $addon->id;
        $this->name = $addon->name;
        $this->description = (string) $addon->description;
        $this->kind = $addon->kind->value;
        $this->limitKey = $addon->limit_key?->value ?? 'products';
        $this->quantity = (string) ($addon->quantity ?? '');
        $this->featureKey = $addon->feature_key?->value ?? 'whatsapp';
        $this->price = (string) (float) $addon->price;
        $this->billing = $addon->billing === AddonBilling::OneOff ? 'monthly' : $addon->billing->value;
        $this->isActive = $addon->is_active;

        $this->dispatch('open-modal', name: 'addon-form');
    }

    public function save(): void
    {
        $this->authorizeSuperAdmin();

        $pack = $this->kind === AddonKind::LimitPack->value;

        $this->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', Rule::enum(AddonKind::class)],
            'limitKey' => [$pack ? 'required' : 'nullable', Rule::enum(LimitKey::class)],
            'quantity' => [$pack ? 'required' : 'nullable', 'integer', 'min:1', 'max:10000000'],
            'featureKey' => [$pack ? 'nullable' : 'required', Rule::enum(FeatureKey::class)],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999.99'],
            'billing' => [$pack ? 'nullable' : 'required', 'in:monthly,yearly'],
        ]);

        $data = [
            'name' => trim($this->name),
            'description' => trim($this->description) !== '' ? trim($this->description) : null,
            'kind' => $this->kind,
            'limit_key' => $pack ? $this->limitKey : null,
            'quantity' => $pack ? (int) $this->quantity : null,
            'feature_key' => $pack ? null : $this->featureKey,
            'price' => $this->price,
            'billing' => $pack ? AddonBilling::OneOff->value : $this->billing,
            'is_active' => $this->isActive,
        ];

        if ($this->editingId) {
            Addon::findOrFail($this->editingId)->update($data);
            $this->toastSuccess('Add-on updated. Shops that already bought it keep what they paid for.');
        } else {
            Addon::create($data + ['sort_order' => (int) Addon::max('sort_order') + 1]);
            $this->toastSuccess('Add-on created.');
        }

        $this->dispatch('close-modal', name: 'addon-form');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeSuperAdmin();

        $addon = Addon::findOrFail($id);
        $addon->update(['is_active' => ! $addon->is_active]);

        $this->toastSuccess($addon->is_active ? 'Add-on is now offered to shops.' : 'Add-on hidden. Shops that bought it keep it until it ends.');
    }

    public function move(int $id, string $direction): void
    {
        $this->authorizeSuperAdmin();

        DB::transaction(function () use ($id, $direction) {
            $ids = Addon::ordered()->lockForUpdate()->pluck('id')->all();
            $position = array_search($id, $ids, true);
            $target = $direction === 'up' ? $position - 1 : $position + 1;

            if ($position === false || ! isset($ids[$target])) {
                return;
            }

            [$ids[$position], $ids[$target]] = [$ids[$target], $ids[$position]];

            foreach ($ids as $index => $addonId) {
                Addon::whereKey($addonId)->update(['sort_order' => $index + 1]);
            }
        });
    }

    public function delete(int $id): void
    {
        $this->authorizeSuperAdmin();

        $addon = Addon::withCount('purchases')->findOrFail($id);

        if ($addon->purchases_count > 0) {
            $this->toastError('Shops have bought this add-on. Deactivate it instead — their purchases stay intact.');

            return;
        }

        $addon->delete();
        $this->toastSuccess('Add-on deleted.');
    }

    public function with(): array
    {
        $this->authorizeSuperAdmin();

        return [
            'addons' => Addon::ordered()->withCount(['purchases', 'purchases as active_count' => fn ($q) => $q->inEffect()])->get(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Add-ons</h1>
    </x-slot>

    <x-ui.card title="Add-on catalogue" description="Shops on a plan can buy active add-ons from Plan & Billing. Payment goes through the same bank-transfer review as signups, and the add-on switches on when you confirm the payment.">
        <x-slot name="actions">
            <x-ui.button size="sm" wire:click="openCreate">New Add-on</x-ui.button>
        </x-slot>

        @if ($addons->isEmpty())
            <x-ui.empty-state title="No add-ons yet" description="Create one to offer extra allowance or a feature on top of a plan." />
        @else
            <x-ui.table :headers="['Order', 'Add-on', 'Gives', 'Price', 'Shops', 'Status', '']">
                @foreach ($addons as $addon)
                    <x-ui.table-row wire:key="addon-{{ $addon->id }}">
                        <x-ui.table-cell>
                            <div class="flex gap-1">
                                <button type="button" wire:click="move({{ $addon->id }}, 'up')" @disabled($loop->first) class="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" aria-label="Move {{ $addon->name }} up">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 15.75 7.5-7.5 7.5 7.5" /></svg>
                                </button>
                                <button type="button" wire:click="move({{ $addon->id }}, 'down')" @disabled($loop->last) class="rounded p-1 text-slate-500 hover:bg-slate-100 disabled:opacity-30" aria-label="Move {{ $addon->name }} down">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" /></svg>
                                </button>
                            </div>
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            <p class="font-medium text-slate-900">{{ $addon->name }}</p>
                            <p class="text-xs text-slate-500">{{ $addon->kind->label() }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $addon->grants() }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">{{ $addon->priceLabel() }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $addon->active_count }} active · {{ $addon->purchases_count }} total</x-ui.table-cell>
                        <x-ui.table-cell><x-ui.badge :variant="$addon->is_active ? 'success' : 'neutral'">{{ $addon->is_active ? 'Offered' : 'Hidden' }}</x-ui.badge></x-ui.table-cell>
                        <x-ui.table-cell>
                            <div class="flex justify-end gap-1">
                                <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $addon->id }})">Edit</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $addon->id }})">{{ $addon->is_active ? 'Hide' : 'Offer' }}</x-ui.button>
                                <x-ui.button size="sm" variant="ghost" wire:click="delete({{ $addon->id }})" wire:confirm="Delete the {{ $addon->name }} add-on permanently?">Delete</x-ui.button>
                            </div>
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    </x-ui.card>

    <x-ui.modal name="addon-form" max-width="lg">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Add-on' : 'New Add-on' }}</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="addonName">
                    <x-ui.input wire:model="name" id="addonName" maxlength="80" placeholder="e.g. Extra 1,000 WhatsApp messages" />
                </x-ui.field>
                <x-ui.field label="Description" name="description" for="addonDescription" help="Optional — shown to the shop owner">
                    <x-ui.input wire:model="description" id="addonDescription" maxlength="255" />
                </x-ui.field>

                <fieldset>
                    <legend class="text-sm font-medium text-slate-700">Type</legend>
                    <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                        @foreach (AddonKind::cases() as $option)
                            <label @class(['flex cursor-pointer items-center gap-2 rounded-lg border p-3 text-sm', 'border-brand-500 bg-brand-50' => $kind === $option->value, 'border-slate-200' => $kind !== $option->value])>
                                <input type="radio" wire:model.live="kind" value="{{ $option->value }}" class="text-brand-600 focus:ring-brand-500"> {{ $option->label() }}
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                @if ($kind === 'limit_pack')
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Adds to" name="limitKey" for="addonLimit">
                            <x-ui.select wire:model="limitKey" id="addonLimit">
                                @foreach (LimitKey::cases() as $limit)
                                    <option value="{{ $limit->value }}">{{ $limit->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Quantity" name="quantity" for="addonQuantity" help="Added on top of the plan’s limit">
                            <x-ui.input wire:model="quantity" id="addonQuantity" type="number" min="1" />
                        </x-ui.field>
                    </div>
                    <p class="text-xs text-slate-500">Packs are one-off and last until the shop’s plan renews.</p>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field label="Switches on" name="featureKey" for="addonFeature">
                            <x-ui.select wire:model="featureKey" id="addonFeature">
                                @foreach (FeatureKey::cases() as $feature)
                                    <option value="{{ $feature->value }}">{{ $feature->label() }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        <x-ui.field label="Billed" name="billing" for="addonBilling">
                            <x-ui.select wire:model="billing" id="addonBilling">
                                <option value="monthly">Every month</option>
                                <option value="yearly">Every year</option>
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                @endif

                <x-ui.field label="Price (Rs)" name="price" for="addonPrice">
                    <x-ui.input wire:model="price" id="addonPrice" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.checkbox wire:model="isActive" label="Offer to shops" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'addon-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">{{ $editingId ? 'Save Add-on' : 'Create Add-on' }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
