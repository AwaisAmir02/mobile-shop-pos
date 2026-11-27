<?php

use App\Enums\WholesaleTool;
use App\Models\Vendor;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Vendor Profile')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource, \App\Livewire\Concerns\Toasts;

    public Vendor $vendor;

    // Credit the vendor owes back (debit notes, claims, overpaid cheques).
    public ?int $refundingId = null;
    public string $refundAmount = '';

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(Vendor $vendor): void
    {
        $this->vendor = $vendor;
    }

    protected function canManageCredit(): bool
    {
        return Auth::user()->hasAccessTo('stock-ins');
    }

    public function applyCredit(int $id): void
    {
        abort_unless($this->canManageCredit(), 403);
        $taken = \App\Actions\VendorCredits::applyToStock(\App\Models\VendorCredit::where('vendor_id', $this->vendor->id)->findOrFail($id), Auth::id());
        $taken > 0 ? $this->toastSuccess('Rs '.number_format($taken, 2).' taken off what you owe them.') : $this->toastError('Nothing is owed to this vendor right now.');
    }

    public function openRefund(int $id): void
    {
        abort_unless($this->canManageCredit(), 403);
        $credit = \App\Models\VendorCredit::where('vendor_id', $this->vendor->id)->findOrFail($id);
        $this->refundingId = $credit->id;
        $this->refundAmount = (string) $credit->remaining;
        $this->paidFrom = 'cash';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'vendor-refund');
    }

    public function refund(): void
    {
        abort_unless($this->canManageCredit(), 403);
        $this->validate(['refundAmount' => ['required', 'numeric', 'min:0.01']], [], ['refundAmount' => 'amount']);
        [$method, $accountId] = $this->paidFromParts();
        \App\Actions\VendorCredits::refund(\App\Models\VendorCredit::where('vendor_id', $this->vendor->id)->findOrFail($this->refundingId), (float) $this->refundAmount, $method, $accountId, Auth::id());

        $this->dispatch('close-modal', name: 'vendor-refund');
        $this->toastSuccess('Money back recorded.');
    }

    public function with(): array
    {
        $this->vendor->load('stockIns.product');

        return [
            'stockIns' => $this->vendor->stockIns,
            'totalUnits' => \App\Support\Quantity::sum($this->vendor->stockIns->filter(fn ($entry) => ! $entry->unit->allowsDecimals())->pluck('quantity')),
            'measuredTotals' => \App\Enums\QuantityUnit::summarize($this->vendor->stockIns->filter(fn ($entry) => $entry->unit->allowsDecimals())->groupBy(fn ($entry) => $entry->unit->value)->map(fn ($entries) => \App\Support\Quantity::sum($entries->pluck('quantity')))->all()),
            'totalCost' => $this->vendor->stockIns->sum('total_cost'),
            'totalOwed' => $this->vendor->totalOwed(),
            'credits' => \App\Models\VendorCredit::query()->where('vendor_id', $this->vendor->id)->where('status', \App\Enums\RecordStatus::Active->value)->latest('id')->get(),
            'creditLeft' => \App\Actions\VendorCredits::available($this->vendor->id),
            'linkedCustomer' => Auth::user()->shop?->usesTool(WholesaleTool::Contra) ? $this->vendor->linkedCustomer() : null,
            'canManageCredit' => $this->canManageCredit(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header
            back="{{ route('vendors.index') }}"
            back-label="Vendors"
            :subtitle="collect([$vendor->company_name, $vendor->phone])->filter()->implode(' · ') ?: null"
        >{{ $vendor->name }}</x-ui.page-header>
    </x-slot>

    <x-party.tabs type="vendor" :party="$vendor" active="profile" />

    <x-ui.stat-row>
        <x-ui.stat label="Total Owed to Vendor" value="Rs {{ number_format($totalOwed, 2) }}" />
        <x-ui.stat label="Total Purchase Cost" value="Rs {{ number_format($totalCost, 2) }}" />
        <x-ui.stat label="Stock Entries" :value="number_format($stockIns->count())" />
        <x-ui.stat label="Total Units Purchased" :value="number_format($totalUnits)" />
        @if ($measuredTotals !== '')
            <x-ui.stat label="Weight, Volume & Length" :value="$measuredTotals" />
        @endif
    </x-ui.stat-row>

    @if ($credits->isNotEmpty() || $linkedCustomer)
        <div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2">
            @if ($credits->isNotEmpty())
                <x-ui.card title="They owe you back" :description="'Rs '.number_format($creditLeft, 2).' left, from debit notes, scheme claims and overpaid cheques'" data-vendor-credits>
                    <ul class="divide-y divide-line text-sm">
                        @foreach ($credits as $credit)
                            <li wire:key="credit-{{ $credit->id }}" class="flex flex-wrap items-center justify-between gap-3 py-2">
                                <span>{{ $credit->sourceLabel() }} · Rs {{ number_format($credit->amount, 2) }}</span>
                                <span class="flex items-center gap-2">
                                    <span class="font-semibold tabular-nums text-ink">Rs {{ number_format($credit->remaining, 2) }} left</span>
                                    @if ($canManageCredit && (float) $credit->remaining > 0)
                                        <x-ui.button size="sm" variant="secondary" wire:click="applyCredit({{ $credit->id }})">Take off stock</x-ui.button>
                                        <x-ui.button size="sm" variant="secondary" wire:click="openRefund({{ $credit->id }})">Paid back</x-ui.button>
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif
            @if ($linkedCustomer)
                <x-ui.card title="Also a customer" description="Contra with them is on their customer page.">
                    <a href="{{ route('customers.show', $linkedCustomer) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $linkedCustomer->displayName() }}</a>
                </x-ui.card>
            @endif
        </div>

        <x-ui.modal name="vendor-refund" max-width="sm">
            <form wire:submit="refund" class="p-6">
                <h2 class="text-lg font-bold text-ink">Money Back from {{ $vendor->name }}</h2>
                <div class="mt-4 space-y-4">
                    <x-ui.field label="Amount (Rs)" name="refundAmount" for="refundAmount">
                        <x-ui.input wire:model="refundAmount" id="refundAmount" type="number" min="0.01" step="0.01" />
                    </x-ui.field>
                    <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'vendor-refund' })">Cancel</x-ui.button>
                    <x-ui.button type="submit">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    <x-ui.card class="mb-6">
        <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-ink-faint">Phone</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->phone ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Company / Shop Name</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->company_name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Address</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->address ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-ink-faint">Notes</dt>
                <dd class="mt-0.5 font-medium text-ink">{{ $vendor->notes ?? '—' }}</dd>
            </div>
        </dl>
    </x-ui.card>

    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 class="text-base font-bold tracking-tight text-ink">Stock Entries</h2>
            <p class="text-sm text-ink-faint">Every stock-in entry recorded from this vendor.</p>
        </div>
        @if (auth()->user()->hasAccessTo('stock-ins'))
            <x-ui.button href="{{ route('stock-ins.history', ['vendorFilter' => $vendor->id]) }}" wire:navigate variant="secondary" size="sm">
                Record payments in Stock In
            </x-ui.button>
        @endif
    </div>

    @if ($stockIns->isEmpty())
        <x-ui.empty-state
            title="No stock entries yet"
            description="Stock logged from this vendor on the Stock In screen will show up here."
        />
    @else
        <x-ui.table stack :headers="['Product', 'Date', 'Quantity', 'Cost', 'Payment', 'Owed', 'Note']">
            @foreach ($stockIns as $entry)
                <x-ui.table-row wire:key="vendor-stock-in-{{ $entry->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $entry->product_name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Date">{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell label="Quantity" class="font-semibold text-success">+{{ $entry->unit->groupedAmount($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Cost">{{ $entry->total_cost !== null ? 'Rs '.number_format($entry->total_cost, 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($entry->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Owed" class="font-semibold text-ink">
                        {{ $entry->amountOwed() > 0 ? 'Rs '.number_format($entry->amountOwed(), 2) : '—' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Note" class="max-w-[14rem] truncate" title="{{ $entry->note }}">{{ $entry->note ?? '—' }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
