<?php

use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\ShopSim;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('My SIMs')] class extends Component
{
    use Toasts;

    public ?int $editingId = null;

    public string $number = '';
    public string $networkChoice = 'Jazz';
    public string $customNetwork = '';
    public bool $is_active = true;

    public function with(): array
    {
        return [
            'sims' => ShopSim::query()->orderByDesc('is_active')->orderBy('number')->get(),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'sim-form');
    }

    public function openEdit(int $id): void
    {
        $sim = ShopSim::findOrFail($id);

        $this->editingId = $sim->id;
        $this->number = $sim->number;
        $this->is_active = $sim->is_active;

        if (in_array($sim->network, BalanceLoad::NETWORKS, true)) {
            $this->networkChoice = $sim->network;
            $this->customNetwork = '';
        } else {
            $this->networkChoice = 'Other';
            $this->customNetwork = $sim->network;
        }

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'sim-form');
    }

    public function save(): void
    {
        $rules = [
            'number' => ['required', 'string', 'max:20', Rule::unique('shop_sims', 'number')->where('shop_id', auth()->user()->shop_id)->ignore($this->editingId)],
            'networkChoice' => ['required', 'in:'.implode(',', BalanceLoad::NETWORKS)],
            'is_active' => ['boolean'],
        ];

        if ($this->networkChoice === 'Other') {
            $rules['customNetwork'] = ['required', 'string', 'max:255'];
        }

        $this->validate($rules);

        $sim = $this->editingId ? ShopSim::findOrFail($this->editingId) : new ShopSim;

        $sim->fill([
            'number' => $this->number,
            'network' => $this->networkChoice === 'Other' ? $this->customNetwork : $this->networkChoice,
            'is_active' => $this->is_active,
        ])->save();

        $this->toastSuccess($this->editingId ? 'SIM updated.' : 'SIM added.');
        $this->dispatch('close-modal', name: 'sim-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        ShopSim::findOrFail($id)->delete();

        $this->toastSuccess('SIM removed.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'sim-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'number', 'customNetwork']);
        $this->networkChoice = 'Jazz';
        $this->is_active = true;
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">My SIMs</h1>
    </x-slot>

    <p class="mb-4 text-sm text-slate-500">
        SIM cards your shop owns and uses to perform balance top-ups and wallet loads for customers — separate from SIM/eSIM products you sell.
    </p>

    <div class="mb-4 flex justify-end">
        <x-ui.button wire:click="openCreate">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add SIM
        </x-ui.button>
    </div>

    @if ($sims->isEmpty())
        <x-ui.empty-state
            title="No SIMs yet"
            description="Add the SIM cards your shop operates from to keep track of them."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add SIM</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Number', 'Network', 'Status', '']">
            @foreach ($sims as $sim)
                <x-ui.table-row wire:key="sim-{{ $sim->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $sim->number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $sim->network }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($sim->is_active)
                            <x-ui.badge variant="success">Active</x-ui.badge>
                        @else
                            <x-ui.badge variant="neutral">Inactive</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $sim->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $sim->id }})"
                                wire:confirm="Remove SIM {{ $sim->number }}?"
                                class="text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="sim-form" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit SIM' : 'Add SIM' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="SIM Number" name="number" for="number">
                    <x-ui.input wire:model="number" id="number" type="tel" placeholder="03xx-xxxxxxx" autofocus />
                </x-ui.field>

                <x-ui.field label="Network" name="networkChoice" for="networkChoice">
                    <x-ui.select wire:model.live="networkChoice" id="networkChoice">
                        @foreach (BalanceLoad::NETWORKS as $network)
                            <option value="{{ $network }}">{{ $network }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                @if ($networkChoice === 'Other')
                    <x-ui.field label="Network Name" name="customNetwork" for="customNetwork">
                        <x-ui.input wire:model="customNetwork" id="customNetwork" />
                    </x-ui.field>
                @endif

                <x-ui.checkbox wire:model="is_active" label="Active / usable" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add SIM' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
