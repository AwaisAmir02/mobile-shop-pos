<?php

use App\Actions\CreateVendor;
use App\Livewire\Concerns\Toasts;
use App\Models\PartyLedgerEntry;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\BranchContext;
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
            'vendors' => Vendor::query()
                ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")))
                ->orderBy('name')
                // Loaded once for the page so each row's Owed is Vendor::totalOwed(),
                // the same figure as the vendor's own page, without a query per row.
                ->with('stockIns')
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

        if (PurchaseOrder::withoutGlobalScope(ExcludesCancelledScope::class)->where('vendor_id', $vendor->id)->exists()) {
            $this->toastError("Cannot delete \"{$vendor->name}\" — it has purchase orders.");

            return;
        }

        // Party Ledger opening balances and adjustments, cancelled ones included.
        if (PartyLedgerEntry::withoutGlobalScope(ExcludesCancelledScope::class)->where('vendor_id', $vendor->id)->exists()) {
            $this->toastError("Cannot delete \"{$vendor->name}\" — it has Party Ledger entries.");

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
        <x-ui.page-header>Vendors</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="$search !== '' ? 1 : 0">
        <x-ui.field label="Search" for="vendor-search" class="w-full sm:w-72">
            <x-ui.input wire:model.live.debounce.400ms="search" id="vendor-search" type="search" placeholder="Name or phone…" />
        </x-ui.field>

        <x-slot:actions>
            <x-ui.button wire:click="openCreate" class="shrink-0">
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                </svg>
                Add Vendor
            </x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($vendors->isEmpty())
        <x-ui.empty-state
            :title="$search !== '' ? 'No vendors match' : 'No vendors yet'"
            :description="$search !== '' ? 'Try another name or phone number.' : 'Add your first vendor to start tracking which stock comes from where.'"
        />
    @else
        <x-ui.table stack :headers="['Name', 'Phone', 'Company', 'Owed', '']">
            @foreach ($vendors as $vendor)
                <x-ui.table-row wire:key="vendor-{{ $vendor->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('vendors.show', $vendor) }}" wire:navigate class="hover:text-link hover:underline">{{ $vendor->name }}</a>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Phone">{{ $vendor->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Company">{{ $vendor->company_name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Owed" data-balance>
                        <x-party.balance :amount="$vendor->totalOwed()" direction="vendor" />
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $vendor->name }}">
                            <x-ui.menu-item href="{{ route('vendors.show', $vendor) }}" wire:navigate>View</x-ui.menu-item>
                            <x-ui.menu-item wire:click="openEdit({{ $vendor->id }})">Edit</x-ui.menu-item>
                            <x-ui.menu-divider />
                            <x-ui.menu-item
                                danger
                                wire:click="delete({{ $vendor->id }})"
                                confirm="Delete {{ $vendor->name }}? This cannot be undone."
                                confirm-label="Delete"
                            >Delete</x-ui.menu-item>
                        </x-ui.row-actions>
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
            <h2 class="text-lg font-bold text-ink">
                {{ $editingId ? 'Edit Vendor' : 'Add Vendor' }}
            </h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="name" required>
                    <x-ui.input wire:model="name" id="name" autofocus />
                </x-ui.field>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Phone" name="phone" for="phone">
                        <x-ui.input wire:model="phone" id="phone" type="tel" placeholder="03xx-xxxxxxx" />
                    </x-ui.field>

                    <x-ui.field label="Company / Shop Name" name="company_name" for="company_name">
                        <x-ui.input wire:model="company_name" id="company_name" />
                    </x-ui.field>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Address" name="address" for="address">
                        <x-ui.input wire:model="address" id="address" />
                    </x-ui.field>

                    <x-ui.field label="Notes" name="notes" for="notes">
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
