<?php

use App\Actions\CreateCustomer;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\ReportQueries\UdhaarReport;
use App\Support\BranchContext;
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

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

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
            // The Udhaar list's own figures (loans plus unpaid sales and counter
            // services), keyed by customer, so both screens always agree.
            'balances' => (new UdhaarReport)->rows()->keyBy(fn (array $row) => $row['customer']->id),
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
        $this->validate(CreateCustomer::rules());

        if ($this->editingId) {
            Customer::findOrFail($this->editingId)->update([
                'name' => $this->name,
                'phone' => $this->phone !== '' ? $this->phone : null,
                'address' => $this->address !== '' ? $this->address : null,
            ]);
        } else {
            CreateCustomer::handle($this->name, $this->phone, $this->address);
        }

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
        <x-ui.page-header>Customers</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="$search !== '' ? 1 : 0">
        <x-ui.field label="Search" for="customer-search" class="w-full sm:w-72">
            <x-ui.input wire:model.live.debounce.400ms="search" id="customer-search" type="search" placeholder="Name or phone…" />
        </x-ui.field>

        <x-slot:actions>
            <x-ui.button wire:click="openCreate" class="shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Customer
            </x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($customers->isEmpty())
        <x-ui.empty-state
            :title="$search !== '' ? 'No customers match' : 'No customers yet'"
            :description="$search !== '' ? 'Try another name or phone number.' : 'Add your first customer to start tracking their sales and history.'"
        />
    @else
        <x-ui.table stack :headers="['Name', 'Phone', 'Address', 'Balance', '']">
            @foreach ($customers as $customer)
                @php
                    $balance = $balances->get($customer->id)['balance'] ?? 0.0;
                @endphp
                <x-ui.table-row wire:key="customer-{{ $customer->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:text-link hover:underline">{{ $customer->name }}</a>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Phone">{{ $customer->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Address" class="max-w-[16rem] truncate">{{ $customer->address ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Balance" data-balance>
                        <x-party.balance :amount="$balance" />
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $customer->name }}">
                            <x-ui.menu-item href="{{ route('customers.show', $customer) }}" wire:navigate>View</x-ui.menu-item>
                            <x-ui.menu-item wire:click="openEdit({{ $customer->id }})">Edit</x-ui.menu-item>
                            <x-ui.menu-divider />
                            <x-ui.menu-item
                                danger
                                wire:click="delete({{ $customer->id }})"
                                confirm="Delete {{ $customer->name }}? This cannot be undone."
                                confirm-label="Delete"
                            >Delete</x-ui.menu-item>
                        </x-ui.row-actions>
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
            <h2 class="text-lg font-bold text-ink">
                {{ $editingId ? 'Edit Customer' : 'Add Customer' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="name" required>
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Phone" name="phone" for="phone">
                        <x-ui.input wire:model="phone" id="phone" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Address / Notes" name="address" for="address">
                        <x-ui.input wire:model="address" id="address" />
                    </x-ui.field>
                </div>
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
