<?php

use App\Actions\CreateCustomer;
use App\Livewire\Concerns\Toasts;
use Livewire\Volt\Component;

new class extends Component
{
    use Toasts;

    public string $name = '';
    public string $phone = '';
    // Wholesale businesses: the shop or company the customer runs.
    public string $businessName = '';

    public function save(): void
    {
        $this->validate([
            'name' => CreateCustomer::rules()['name'],
            'phone' => CreateCustomer::rules()['phone'],
            'businessName' => ['nullable', 'string', 'max:255'],
        ], [], ['businessName' => 'business name']);

        $wholesale = (bool) \Illuminate\Support\Facades\Auth::user()->shop?->isWholesale();
        $customer = CreateCustomer::handle($this->name, $this->phone, null, $wholesale && trim($this->businessName) !== '' ? ['business_name' => trim($this->businessName)] : []);

        $this->toastSuccess('Customer added.');
        $this->dispatch('close-modal', name: 'quick-create-customer');
        $this->dispatch('customer-created', customerId: $customer->id, customerName: $customer->name);
        $this->reset(['name', 'phone', 'businessName']);
    }

    public function close(): void
    {
        $this->dispatch('close-modal', name: 'quick-create-customer');
        $this->reset(['name', 'phone', 'businessName']);
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-ui.modal name="quick-create-customer" max-width="sm">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-ink">New Customer</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Name" name="name" for="quickCustomerName">
                    <x-ui.input wire:model="name" id="quickCustomerName" autofocus />
                </x-ui.field>

                <x-ui.field label="Phone" name="phone" for="quickCustomerPhone">
                    <x-ui.input wire:model="phone" id="quickCustomerPhone" type="tel" placeholder="03xx-xxxxxxx" />
                </x-ui.field>

                @if (auth()->user()->shop?->isWholesale())
                    <x-ui.field label="Business name" name="businessName" for="quickCustomerBusiness" help="Optional. More details, price and credit on the Customers list">
                        <x-ui.input wire:model="businessName" id="quickCustomerBusiness" />
                    </x-ui.field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="close">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    Add Customer
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
