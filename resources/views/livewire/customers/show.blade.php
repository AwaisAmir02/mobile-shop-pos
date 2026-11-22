<?php

use App\Actions\StoreCredit;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\StoreCreditEntry;
use Illuminate\Support\Facades\Auth;
use App\ReportQueries\UdhaarReport;
use App\Support\BranchContext;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Customer Profile')] class extends Component
{
    use ChoosesPaymentSource;
    use Toasts;

    public Customer $customer;

    public string $cashOutAmount = '';
    public string $cashOutNote = '';

    /**
     * Customers and vendors belong to the whole shop, so this screen shows
     * every branch's lines and balances to everyone, restricted staff
     * included. boot() runs on every Livewire request, not just the first.
     */
    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(Customer $customer): void
    {
        $this->customer = $customer;
    }

    protected function canCashOut(): bool
    {
        return (bool) Auth::user()->shop?->cash_refunds_allowed && Auth::user()->hasAccessTo('sales-returns');
    }

    public function openCashOut(): void
    {
        abort_unless($this->canCashOut(), 403);

        $this->cashOutAmount = (string) StoreCredit::spendable($this->customer->id);
        $this->cashOutNote = '';
        $this->paidFrom = 'cash';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cash-out-form');
    }

    /** Store credit handed over as money: its own entry, never a return's refund. */
    public function cashOut(): void
    {
        abort_unless($this->canCashOut(), 403);

        $this->validate([
            'cashOutAmount' => ['required', 'numeric', 'min:0.01'],
            'cashOutNote' => ['nullable', 'string', 'max:255'],
        ], [], ['cashOutAmount' => 'amount']);

        [$method, $accountId] = $this->paidFromParts();
        $entry = StoreCredit::cashOut($this->customer, (float) $this->cashOutAmount, $method, $accountId, Auth::id(), $this->cashOutNote);

        $this->dispatch('close-modal', name: 'cash-out-form');
        $this->toastSuccess('Rs '.number_format(abs((float) $entry->amount), 2).' of store credit paid out.');
    }

    public function with(): array
    {
        $this->customer->load(['sales', 'udhaarTransactions']);

        return [
            'sales' => $this->customer->sales,
            'totalSalesRevenue' => $this->customer->sales->sum('total'),
            'salesOutstanding' => $this->customer->salesOutstandingBalance(),
            'udhaarBalance' => $this->customer->udhaarBalance(),
            'udhaarStatus' => $this->customer->udhaarStatus(),
            // Everything they owe, exactly as the Udhaar list and the Customers
            // list's Balance column show it (loans plus unpaid sales and counter
            // services). Read from the same report, so the screens can't disagree.
            'credit' => StoreCredit::balance($this->customer->id),
            'creditSpendable' => StoreCredit::spendable($this->customer->id),
            'creditEntries' => StoreCreditEntry::query()->withoutGlobalScope(\App\Models\Scopes\ExcludesCancelledScope::class)->where('customer_id', $this->customer->id)->latest('entry_date')->latest('id')->limit(50)->get(),
            'canCashOut' => $this->canCashOut(),
            'totalOwed' => (new UdhaarReport)->rows()->first(fn (array $row) => $row['customer']->id === $this->customer->id)['balance'] ?? 0.0,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header
            back="{{ route('customers.index') }}"
            back-label="Customers"
            :subtitle="collect([$customer->phone, $customer->address])->filter()->implode(' · ') ?: null"
        >{{ $customer->name }}</x-ui.page-header>
    </x-slot>

    <x-party.tabs type="customer" :party="$customer" active="profile" />

    <x-ui.stat-row>
        <x-ui.stat label="Total Owed" value="Rs {{ number_format(abs($totalOwed), 2) }}" :sub="$totalOwed < 0 ? 'In advance · as on the Udhaar list' : 'Udhaar, sales and services · as on the Udhaar list'" />
        <x-ui.stat label="Sales Outstanding" value="Rs {{ number_format($salesOutstanding, 2) }}" sub="Unpaid part of their sales" />
        <x-ui.stat label="Udhaar Loans" value="Rs {{ number_format(abs($udhaarBalance), 2) }}" :sub="match ($udhaarStatus) { 'due' => 'Due · given as udhaar', 'advance' => 'Advance · paid ahead', default => 'Settled' }" />
        <x-ui.stat label="Total Sales" value="Rs {{ number_format($totalSalesRevenue, 2) }}" :sub="$sales->count().' sale(s)'" />
    </x-ui.stat-row>

    @if ($creditEntries->isNotEmpty())
        <x-ui.card title="Store Credit" description="Given on returns and spent on later sales. Kept apart from udhaar." class="mb-6" data-store-credit>
            <x-slot name="actions">
                @if ($canCashOut && $creditSpendable > 0)
                    <x-ui.button type="button" variant="secondary" size="sm" wire:click="openCashOut">Pay Out as Money</x-ui.button>
                @endif
            </x-slot>
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1">
                <p class="text-2xl font-extrabold tabular-nums text-ink" data-credit-balance>Rs {{ number_format($credit, 2) }}</p>
                @if (round($creditSpendable, 2) < round($credit, 2))
                    <p class="text-sm text-ink-faint">Rs {{ number_format($creditSpendable, 2) }} can be used; the rest is past its expiry date and will be written off.</p>
                @endif
            </div>
            <ul class="mt-4 divide-y divide-line text-sm">
                @foreach ($creditEntries as $entry)
                    <li wire:key="credit-{{ $entry->id }}" @class(['flex items-center justify-between gap-3 py-2', 'opacity-60' => $entry->isCancelled()])>
                        <span class="min-w-0">
                            <span class="font-medium text-ink">{{ $entry->describe() }}</span>
                            <span class="block text-xs text-ink-faint">
                                {{ $entry->entry_date->format('d M Y') }}
                                @if ($entry->type === 'issue' && $entry->expires_on)
                                    · expires {{ $entry->expires_on->format('d M Y') }}
                                @endif
                                @if ($entry->note)
                                    · {{ $entry->note }}
                                @endif
                                @if ($entry->isCancelled())
                                    · cancelled
                                @endif
                            </span>
                        </span>
                        <span @class(['whitespace-nowrap font-semibold tabular-nums', 'text-success' => (float) $entry->amount > 0, 'text-ink' => (float) $entry->amount <= 0])>{{ (float) $entry->amount > 0 ? '+' : '−' }} Rs {{ number_format(abs((float) $entry->amount), 2) }}</span>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>

        <x-ui.modal name="cash-out-form" max-width="sm">
            <form wire:submit="cashOut" class="p-6">
                <h2 class="text-lg font-bold text-ink">Pay Out Store Credit</h2>
                <p class="mt-2 text-sm text-ink-muted">Hands {{ $customer->name }} money for their store credit. It shows in Cash & Balances as store credit paid out, not as a return.</p>
                <div class="mt-5 space-y-4">
                    <x-ui.field label="Amount (Rs)" name="cashOutAmount" for="cashOutAmount" :help="'Up to Rs '.number_format($creditSpendable, 2)">
                        <x-ui.input wire:model="cashOutAmount" id="cashOutAmount" type="number" min="0.01" step="0.01" :max="$creditSpendable" />
                    </x-ui.field>
                    <x-ledger.paid-from :value="$paidFrom" label="Paid From" />
                    <x-ui.field label="Note" name="cashOutNote" for="cashOutNote" help="Optional">
                        <x-ui.input wire:model="cashOutNote" id="cashOutNote" maxlength="255" />
                    </x-ui.field>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cash-out-form' })">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cashOut">Pay Out</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Sales History" description="Every sale linked to this customer.">
            @if ($sales->isEmpty())
                <x-ui.empty-state
                    title="No sales yet"
                    description="Sales linked to this customer from the POS screen will show up here."
                />
            @else
                <div class="-mx-2 divide-y divide-line">
                    @foreach ($sales as $sale)
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="flex items-center justify-between gap-3 rounded-lg px-2 py-3 transition-colors hover:bg-surface-sunken">
                            <div>
                                <p class="text-sm font-semibold text-ink">{{ $sale->invoiceNumber() }}</p>
                                <p class="text-xs text-ink-faint">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                            </div>
                            <span class="text-sm font-semibold tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-ui.card>

        <x-ui.card title="Udhaar Loans" description="Money given or received as udhaar, kept separate from sales.">
            <div class="flex items-center justify-between rounded-xl border border-line px-4 py-4">
                <div>
                    <p class="text-sm text-ink-faint">Loans balance</p>
                    <p class="text-display-sm tabular-nums text-ink">Rs {{ number_format(abs($udhaarBalance), 2) }}</p>
                </div>
                @if ($udhaarStatus === 'due')
                    <x-ui.badge variant="warning">Due</x-ui.badge>
                @elseif ($udhaarStatus === 'advance')
                    <x-ui.badge variant="brand">Advance</x-ui.badge>
                @else
                    <x-ui.badge variant="success">Settled</x-ui.badge>
                @endif
            </div>

            @if (auth()->user()->hasAccessTo('udhaar'))
                <div class="mt-4">
                    <x-ui.button href="{{ route('udhaar.show', $customer) }}" wire:navigate variant="secondary" class="w-full justify-center">
                        Open their Udhaar
                    </x-ui.button>
                </div>
            @endif
        </x-ui.card>
    </div>
</div>
