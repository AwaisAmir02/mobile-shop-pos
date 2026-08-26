<?php

use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customers')] class extends Component
{
    use Toasts, WithPagination;

    public string $search = '';

    public ?int $editingId = null;

    public string $name = '';
    public string $phone = '';
    public string $address = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function with(): array
    {
        return [
            'customers' => Customer::query()
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
        $this->dispatch('open-modal', name: 'customer-form');
    }

    public function openEdit(int $id): void
    {
        $customer = Customer::findOrFail($id);

        $this->editingId = $customer->id;
        $this->name = $customer->name;
        $this->phone = (string) $customer->phone;
        $this->address = (string) $customer->address;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'customer-form');
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
        ]);

        $customer = $this->editingId ? Customer::findOrFail($this->editingId) : new Customer;

        $customer->fill([
            'name' => $this->name,
            'phone' => $this->phone !== '' ? $this->phone : null,
            'address' => $this->address !== '' ? $this->address : null,
        ])->save();

        $this->toastSuccess($this->editingId ? 'Customer updated.' : 'Customer added.');
        $this->dispatch('close-modal', name: 'customer-form');
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $customer = Customer::findOrFail($id);

        if ($customer->hasFinancialHistory()) {
            $this->toastError("Cannot delete \"{$customer->name}\" — they have existing sales or transaction history.");

            return;
        }

        $customer->delete();
        $this->toastSuccess('Customer deleted.');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'customer-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'phone', 'address']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Customers</h1>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <x-ui.input wire:model.live.debounce.400ms="search" type="search" placeholder="Search by name or phone…" class="sm:max-w-xs" />

        <x-ui.button wire:click="openCreate" class="shrink-0">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
            </svg>
            Add Customer
        </x-ui.button>
    </div>

    @if ($customers->isEmpty())
        <x-ui.empty-state
            title="No customers yet"
            description="Add your first customer to start tracking their sales and history."
        >
            <x-slot name="action">
                <x-ui.button wire:click="openCreate">Add Customer</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Name', 'Phone', 'Address', '']">
            @foreach ($customers as $customer)
                <x-ui.table-row wire:key="customer-{{ $customer->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $customer->name }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $customer->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $customer->address ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $customer->id }})">
                                Edit
                            </x-ui.button>
                            <x-ui.button
                                size="sm"
                                variant="ghost"
                                wire:click="delete({{ $customer->id }})"
                                wire:confirm="Delete {{ $customer->name }}? This cannot be undone."
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
            {{ $customers->links() }}
        </div>
    @endif

    <x-ui.modal name="customer-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">
                {{ $editingId ? 'Edit Customer' : 'Add Customer' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="name">
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                <x-ui.field label="Phone" name="phone" for="phone" help="Optional">
                    <x-ui.input wire:model="phone" id="phone" type="tel" placeholder="03xx-xxxxxxx" />
                </x-ui.field>

                <x-ui.field label="Address / Notes" name="address" for="address" help="Optional">
                    <x-ui.input wire:model="address" id="address" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Customer' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
