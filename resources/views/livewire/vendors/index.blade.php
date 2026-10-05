<?php

use App\Actions\CreateVendor;
use App\Livewire\Concerns\Toasts;
use App\Models\Vendor;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Vendors')] class extends Component
{
    use Toasts, WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';
    public string $phone = '';
    public string $company_name = '';
    public string $address = '';
    public string $notes = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'vendors' => Vendor::query()
                ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")))
                ->orderBy('name')
                ->paginate(10),
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->dispatch('open-modal', name: 'vendor-form');
    }

    public function openEdit(int $id): void
    {
        $vendor = Vendor::findOrFail($id);

        $this->editingId = $vendor->id;
        $this->name = $vendor->name;
        $this->phone = (string) $vendor->phone;
        $this->company_name = (string) $vendor->company_name;
        $this->address = (string) $vendor->address;
        $this->notes = (string) $vendor->notes;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'vendor-form');
    }

    public function save(): void
    {
        $this->name = trim($this->name);

        $this->validate(CreateVendor::rules($this->editingId));

        if ($this->editingId) {
            Vendor::findOrFail($this->editingId)->update([
                'name' => $this->name,
                'phone' => $this->phone !== '' ? $this->phone : null,
                'company_name' => $this->company_name !== '' ? $this->company_name : null,
                'address' => $this->address !== '' ? $this->address : null,
                'notes' => $this->notes !== '' ? $this->notes : null,
            ]);
        } else {
            CreateVendor::handle($this->name, $this->phone, $this->company_name, $this->address, $this->notes);
        }

        $this->toastSuccess($this->editingId ? 'Vendor updated.' : 'Vendor added.');
        $this->dispatch('close-modal', name: 'vendor-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->hasStockInHistory()) {
            $this->toastError("Cannot delete \"{$vendor->name}\" — it has existing stock-in entries.");

            return;
        }

        $vendor->delete();
        $this->toastSuccess('Vendor deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'vendor-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone', 'company_name', 'address', 'notes']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Vendors</h1>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.input wire:model.live.debounce.400ms="search" type="search" placeholder="Search by name or phone…" class="sm:max-w-xs" />

        <x-ui.button wire:click="openCreate" class="shrink-0">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Vendor
        </x-ui.button>
    </div>

    @if ($vendors->isEmpty())
        <x-ui.empty-state
            title="No vendors yet"
            description="Add your first vendor to start tracking which stock comes from where."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add Vendor</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Name', 'Phone', 'Company', '']">
            @foreach ($vendors as $vendor)
                <x-ui.table-row wire:key="vendor-{{ $vendor->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">
                        <a href="{{ route('vendors.show', $vendor) }}" wire:navigate class="hover:text-brand-700">
                            {{ $vendor->name }}
                        </a>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $vendor->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $vendor->company_name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <a href="{{ route('vendors.show', $vendor) }}" wire:navigate>
                                <x-ui.button size="sm" variant="ghost">View</x-ui.button>
                            </a>
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $vendor->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $vendor->id }})"
                                wire:confirm="Delete {{ $vendor->name }}? This cannot be undone."
                                class="text-red-600 hover:bg-red-50"
                            >
                                Delete
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $vendors->links() }}
        </div>
    @endif

    <x-ui.modal name="vendor-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit Vendor' : 'Add Vendor' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Phone" name="phone" for="phone" help="Optional">
                        <x-ui.input wire:model="phone" id="phone" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Company / Shop Name" name="company_name" for="company_name" help="Optional">
                        <x-ui.input wire:model="company_name" id="company_name" />
                    </x-ui.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Address" name="address" for="address" help="Optional">
                        <x-ui.input wire:model="address" id="address" />
                    </x-ui.field>

                    <x-ui.field label="Notes" name="notes" for="notes" help="Optional">
                        <x-ui.input wire:model="notes" id="notes" />
                    </x-ui.field>
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Vendor' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
