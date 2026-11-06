<?php

use App\Livewire\Concerns\Toasts;
use App\Models\DeductionType;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Deduction Types')] class extends Component
{
    use Toasts;

    /** Checked on every action too: the route middleware only runs on the first load. */
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('payroll'), 403);
    }

    /** Also shown as a Settings tab, without the Payroll header and links. */
    public bool $embedded = false;

    public ?int $editingId = null;
    public int $formNonce = 0;
    public string $name = '';
    public string $effect = 'reduces';
    public string $defaultAmount = '';

    public function with(): array
    {
        return ['types' => DeductionType::query()->orderByDesc('is_active')->orderBy('name')->get()];
    }

    public function edit(int $id): void
    {
        $type = DeductionType::findOrFail($id);

        $this->editingId = $type->id;
        $this->name = $type->name;
        $this->effect = $type->reduces_cost ? 'reduces' : 'held';
        $this->defaultAmount = (string) ($type->default_amount ?? '');
        $this->formNonce++;
        $this->resetErrorBag();
    }

    public function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'effect', 'defaultAmount']);
        $this->formNonce++;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('deduction_types', 'name')->where('shop_id', Auth::user()->shop_id)->ignore($this->editingId)],
            'effect' => ['required', Rule::in(['reduces', 'held'])],
            'defaultAmount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ], [], ['defaultAmount' => 'default amount']);

        $type = $this->editingId ? DeductionType::findOrFail($this->editingId) : new DeductionType;
        $type->fill([
            'name' => $this->name,
            'reduces_cost' => $this->effect === 'reduces',
            'default_amount' => $this->defaultAmount !== '' ? $this->defaultAmount : null,
        ])->save();

        $this->toastSuccess('Deduction type saved.');
        $this->resetForm();
    }

    public function toggleActive(int $id): void
    {
        $type = DeductionType::findOrFail($id);
        $type->update(['is_active' => ! $type->is_active]);
    }
}; ?>

<div>
    @unless ($embedded)
        <x-slot name="header">
            <h1 class="text-xl font-semibold text-slate-900">Deduction Types</h1>
        </x-slot>

        <x-payroll.nav active="payroll.deduction-types" />
    @endunless

    <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
        <span class="font-semibold">This isn't tax advice.</span> The app applies no tax, EOBI, SESSI or other legal rules. You decide what to deduct and how much, so please check what applies to your shop with a qualified advisor.
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card :title="$editingId ? 'Edit Deduction Type' : 'Add Deduction Type'">
            <form wire:submit="save" class="space-y-4" wire:key="deduction-form-{{ $formNonce }}">
                <x-ui.field label="Name" name="name" for="deductionName" help="e.g. Unpaid leave, Fine, Withholding">
                    <x-ui.input wire:model="name" id="deductionName" />
                </x-ui.field>
                <x-ui.field label="What it does" name="effect" for="deductionEffect">
                    <x-ui.select wire:model="effect" id="deductionEffect">
                        <option value="reduces">Reduces salary cost (fine, unpaid leave)</option>
                        <option value="held">Held for someone else (still a cost, not take-home)</option>
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="Default Amount" name="defaultAmount" for="deductionDefault" help="Optional. Added to every new draft. You can change it on each payslip">
                    <x-ui.input wire:model="defaultAmount" id="deductionDefault" type="number" min="0" step="0.01" />
                </x-ui.field>
                <div class="flex gap-2">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
                    @if ($editingId)
                        <x-ui.button type="button" variant="secondary" wire:click="resetForm">Cancel</x-ui.button>
                    @endif
                </div>
            </form>
        </x-ui.card>

        <div class="lg:col-span-2">
            @if ($types->isEmpty())
                <x-ui.empty-state title="No deduction types yet" description="Add the ones your shop uses." />
            @else
                <x-ui.table :headers="['Name', 'What it does', 'Default', '']">
                    @foreach ($types as $type)
                        <x-ui.table-row wire:key="deduction-{{ $type->id }}" @class(['opacity-50' => ! $type->is_active])>
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $type->name }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $type->reduces_cost ? 'Reduces salary cost' : 'Held for someone else' }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $type->default_amount !== null ? 'Rs '.number_format($type->default_amount, 2) : '—' }}</x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex justify-end gap-2">
                                    <x-ui.button size="sm" variant="ghost" wire:click="edit({{ $type->id }})">Edit</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="toggleActive({{ $type->id }})">{{ $type->is_active ? 'Turn off' : 'Turn on' }}</x-ui.button>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </div>
    </div>
</div>
