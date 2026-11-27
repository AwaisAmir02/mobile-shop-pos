<?php

use App\Actions\CreateCustomer;
use App\Enums\WholesaleTool;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\ReportQueries\UdhaarReport;
use App\Support\BranchContext;
use App\Support\PriceTiers;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Customers')] class extends Component
{
    use Toasts, WithPagination;

    public string $search = '';

    // Wholesale businesses only: one area / route at a time.
    public string $areaFilter = '';

    public ?int $editingId = null;

    public string $name = '';
    public string $phone = '';
    public string $address = '';

    // Business details, price level and credit (wholesale businesses; see CreateCustomer::businessFields()).
    public string $business_name = '';
    public string $contact_person = '';
    public string $phone_2 = '';
    public string $area = '';
    public string $ntn = '';
    public string $strn = '';
    public string $cnic = '';
    public string $price_tier = '';
    public string $credit_limit = '';
    public string $payment_terms_days = '';

    // Wholesale P2: the route (distributors) and the same party's vendor record (traders).
    public string $routeId = '';
    public string $routeSequence = '';
    public string $linkedVendorId = '';

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

    public function updatingAreaFilter(): void
    {
        $this->resetPage();
    }

    protected function isWholesale(): bool
    {
        return (bool) Auth::user()->shop?->isWholesale();
    }

    public function with(): array
    {
        $wholesale = $this->isWholesale();

        return [
            'wholesale' => $wholesale,
            'customers' => Customer::query()
                ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
                    ->where('name', 'like', "%{$this->search}%")
                    ->orWhere('phone', 'like', "%{$this->search}%")
                    ->when($wholesale, fn ($q) => $q
                        ->orWhere('business_name', 'like', "%{$this->search}%")
                        ->orWhere('phone_2', 'like', "%{$this->search}%")
                        ->orWhere('area', 'like', "%{$this->search}%"))))
                ->when($wholesale && $this->areaFilter !== '', fn ($query) => $query->where('area', $this->areaFilter))
                ->orderBy('name')
                ->paginate(10),
            // The Udhaar list's own figures (loans plus unpaid sales and counter
            // services), keyed by customer, so both screens always agree.
            'balances' => (new UdhaarReport)->rows()->keyBy(fn (array $row) => $row['customer']->id),
            'areas' => $wholesale ? Customer::query()->whereNotNull('area')->where('area', '!=', '')->distinct()->orderBy('area')->pluck('area') : collect(),
            'tierOptions' => $wholesale ? PriceTiers::options(Auth::user()->shop) : [],
            'deliveryRoutes' => Auth::user()->shop?->usesTool(WholesaleTool::Routes) ? \App\Models\DeliveryRoute::query()->orderBy('name')->get() : null,
            'linkableVendors' => Auth::user()->shop?->usesTool(WholesaleTool::Contra) && Auth::user()->hasAccessTo('vendors') ? \App\Models\Vendor::query()->orderBy('name')->get() : null,
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

        foreach (CreateCustomer::BUSINESS_FIELDS as $field) {
            $value = $customer->{$field};
            $this->{$field} = (string) ($value instanceof \BackedEnum ? $value->value : ($value ?? ''));
        }

        $this->routeId = (string) ($customer->route_id ?? '');
        $this->routeSequence = (string) ($customer->route_sequence ?? '');
        $this->linkedVendorId = (string) ($customer->vendor_id ?? '');

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'customer-form');
    }

    public function save(): void
    {
        $wholesale = $this->isWholesale();
        $this->validate(CreateCustomer::rules() + ($wholesale ? CreateCustomer::businessRules() : []), [], CreateCustomer::businessAttributes());

        $business = $wholesale ? CreateCustomer::businessAttributesFrom($this->only(CreateCustomer::BUSINESS_FIELDS)) : [];
        $business += $this->p2Attributes();

        if ($this->editingId) {
            Customer::findOrFail($this->editingId)->update([
                'name' => $this->name,
                'phone' => $this->phone !== '' ? $this->phone : null,
                'address' => $this->address !== '' ? $this->address : null,
            ] + $business);
        } else {
            CreateCustomer::handle($this->name, $this->phone, $this->address, $business);
        }

        $this->toastSuccess($this->editingId ? 'Customer updated.' : 'Customer added.');
        $this->dispatch('close-modal', name: 'customer-form');
        $this->resetForm();
    }

    /** Route and linked vendor, for the trades that use them; checked against this shop. */
    protected function p2Attributes(): array
    {
        $shop = Auth::user()->shop;
        $attributes = [];

        if ($shop?->usesTool(WholesaleTool::Routes)) {
            $this->validate(['routeId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('delivery_routes', 'id')->where('shop_id', $shop->id)], 'routeSequence' => ['nullable', 'integer', 'min:1', 'max:9999']], [], ['routeId' => 'route', 'routeSequence' => 'visiting order']);
            $attributes['route_id'] = $this->routeId !== '' ? (int) $this->routeId : null;
            $attributes['route_sequence'] = $this->routeId !== '' && $this->routeSequence !== '' ? (int) $this->routeSequence : null;
        }

        if ($shop?->usesTool(WholesaleTool::Contra) && Auth::user()->hasAccessTo('vendors')) {
            $this->validate(['linkedVendorId' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('vendors', 'id')->where('shop_id', $shop->id), \Illuminate\Validation\Rule::unique('customers', 'vendor_id')->ignore($this->editingId)]], ['linkedVendorId.unique' => 'That vendor is already linked to another customer.'], ['linkedVendorId' => 'vendor']);
            $attributes['vendor_id'] = $this->linkedVendorId !== '' ? (int) $this->linkedVendorId : null;
        }

        return $attributes;
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
        $this->reset(array_merge(['editingId', 'name', 'phone', 'address', 'routeId', 'routeSequence', 'linkedVendorId'], CreateCustomer::BUSINESS_FIELDS));
        $this->resetErrorBag();
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Customers</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="($search !== '' ? 1 : 0) + ($areaFilter !== '' ? 1 : 0)">
        <x-ui.field label="Search" for="customer-search" class="w-full sm:w-72">
            <x-ui.input wire:model.live.debounce.400ms="search" id="customer-search" type="search" :placeholder="$wholesale ? 'Name, business, phone or area…' : 'Name or phone…'" />
        </x-ui.field>
        @if ($wholesale && $areas->isNotEmpty())
            <x-ui.field label="Area / route" for="customer-area-filter" class="w-full sm:w-52">
                <x-ui.select wire:model.live="areaFilter" id="customer-area-filter">
                    <option value="">All areas</option>
                    @foreach ($areas as $areaName)
                        <option value="{{ $areaName }}">{{ $areaName }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif

        <x-slot:actions>
            @if ($wholesale && auth()->user()->hasAccessTo('udhaar'))
                <x-ui.button href="{{ route('dues-aging.index') }}" wire:navigate variant="secondary" class="shrink-0" data-aging-link>Dues aging</x-ui.button>
            @endif
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
            :title="$search !== '' || $areaFilter !== '' ? 'No customers match' : 'No customers yet'"
            :description="$search !== '' || $areaFilter !== '' ? 'Try another name or phone number.' : 'Add your first customer to start tracking their sales and history.'"
        />
    @elseif ($wholesale)
        <x-ui.table stack :headers="['Customer', 'Phone', 'Area', 'Credit', 'Balance', '']">
            @foreach ($customers as $customer)
                @php
                    $balance = $balances->get($customer->id)['balance'] ?? 0.0;
                @endphp
                <x-ui.table-row wire:key="customer-{{ $customer->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:text-link hover:underline">{{ $customer->business_name ?: $customer->name }}</a>
                        @if ($customer->business_name)
                            <span class="block text-xs font-normal text-ink-faint">{{ $customer->contact_person ?: $customer->name }}</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Phone">{{ $customer->phone ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Area">{{ $customer->area ?: '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Credit" class="text-sm">
                        @if ($customer->credit_limit !== null)
                            <span class="tabular-nums">Rs {{ number_format($customer->credit_limit, 0) }}</span>
                        @endif
                        @if ($customer->payment_terms_days)
                            <span class="block text-xs text-ink-faint">{{ $customer->payment_terms_days }}-day terms</span>
                        @endif
                        @if ($customer->credit_limit === null && ! $customer->payment_terms_days)
                            <span class="text-ink-faint">—</span>
                        @endif
                    </x-ui.table-cell>
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

    <x-ui.modal name="customer-form" :max-width="$wholesale ? '2xl' : 'md'">
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

                @if ($wholesale)
                    <div class="space-y-4 border-t border-line pt-5" data-business-fields>
                        <p class="text-sm font-semibold text-ink">Business details <span class="font-normal text-ink-faint">· all optional</span></p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-ui.field label="Business name" name="business_name" for="business_name" help="The shop or company, as on the bill">
                                <x-ui.input wire:model="business_name" id="business_name" />
                            </x-ui.field>
                            <x-ui.field label="Contact person" name="contact_person" for="contact_person">
                                <x-ui.input wire:model="contact_person" id="contact_person" />
                            </x-ui.field>
                            <x-ui.field label="Second phone" name="phone_2" for="phone_2">
                                <x-ui.input wire:model="phone_2" id="phone_2" type="tel" placeholder="03xx-xxxxxxx" />
                            </x-ui.field>
                            <x-ui.field label="Area / route" name="area" for="area" help="Groups customers for recovery rounds and the aging report">
                                <x-ui.input wire:model="area" id="area" list="customer-areas" autocomplete="off" />
                                <datalist id="customer-areas">
                                    @foreach ($areas as $areaName)
                                        <option value="{{ $areaName }}"></option>
                                    @endforeach
                                </datalist>
                            </x-ui.field>
                        </div>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <x-ui.field label="NTN" name="ntn" for="ntn">
                                <x-ui.input wire:model="ntn" id="ntn" maxlength="30" />
                            </x-ui.field>
                            <x-ui.field label="STRN" name="strn" for="strn" help="Sales tax registration">
                                <x-ui.input wire:model="strn" id="strn" maxlength="30" />
                            </x-ui.field>
                            <x-ui.field label="CNIC" name="cnic" for="cnic" help="If not registered">
                                <x-ui.input wire:model="cnic" id="cnic" maxlength="20" placeholder="xxxxx-xxxxxxx-x" />
                            </x-ui.field>
                        </div>
                    </div>

                    <div class="space-y-4 border-t border-line pt-5" data-credit-fields>
                        <p class="text-sm font-semibold text-ink">Price and credit</p>
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                            <x-ui.field label="Sells at" name="price_tier" for="price_tier">
                                <div wire:key="price-tier-{{ $editingId ?? 'new' }}-{{ $price_tier }}">
                                    <x-ui.select wire:model="price_tier" id="price_tier">
                                        @foreach ($tierOptions as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </x-ui.select>
                                </div>
                            </x-ui.field>
                            <x-ui.field label="Credit limit (Rs)" name="credit_limit" for="credit_limit" help="Blank for no limit">
                                <x-ui.input wire:model="credit_limit" id="credit_limit" type="number" min="0" step="1" />
                            </x-ui.field>
                            <x-ui.field label="Payment terms (days)" name="payment_terms_days" for="payment_terms_days" help="Sets the due date on credit sales">
                                <x-ui.input wire:model="payment_terms_days" id="payment_terms_days" type="number" min="0" max="365" step="1" />
                            </x-ui.field>
                        </div>
                    </div>

                    @if ($deliveryRoutes !== null || $linkableVendors !== null)
                        <div class="grid grid-cols-1 gap-4 border-t border-line pt-5 sm:grid-cols-3" data-p2-fields>
                            @if ($deliveryRoutes !== null)
                                <x-ui.field label="Route" name="routeId" for="routeId">
                                    <div wire:key="route-{{ $editingId ?? 'new' }}-{{ $routeId }}">
                                        <x-ui.select wire:model="routeId" id="routeId">
                                            <option value="">No route</option>
                                            @foreach ($deliveryRoutes as $deliveryRoute)
                                                <option value="{{ $deliveryRoute->id }}">{{ $deliveryRoute->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </div>
                                </x-ui.field>
                                <x-ui.field label="Visiting order" name="routeSequence" for="routeSequence" help="Stop number on the route">
                                    <x-ui.input wire:model="routeSequence" id="routeSequence" type="number" min="1" step="1" />
                                </x-ui.field>
                            @endif
                            @if ($linkableVendors !== null)
                                <x-ui.field label="Also a vendor" name="linkedVendorId" for="linkedVendorId" help="The same party you buy from, for contra">
                                    <div wire:key="linked-vendor-{{ $editingId ?? 'new' }}-{{ $linkedVendorId }}">
                                        <x-ui.select wire:model="linkedVendorId" id="linkedVendorId">
                                            <option value="">Not a vendor</option>
                                            @foreach ($linkableVendors as $vendor)
                                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                                            @endforeach
                                        </x-ui.select>
                                    </div>
                                </x-ui.field>
                            @endif
                        </div>
                    @endif
                @endif
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
