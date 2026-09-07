<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\ShopAccount;
use App\Models\WalletLoad;
use App\Models\WalletProvider;
use App\Services\WalletLoadReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Wallet Load History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $provider = '';
    public string $shopAccountId = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingProvider(): void
    {
        $this->resetPage();
    }

    public function updatingShopAccountId(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return WalletLoad::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->provider, fn ($query) => $query->where('provider', $this->provider))
            ->when($this->shopAccountId, fn ($query) => $query->where('shop_account_id', $this->shopAccountId))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function with(): array
    {
        return [
            'loads' => $this->filteredQuery()->latest()->paginate(15),
            'totalLoaded' => $this->filteredQuery()->sum('amount'),
            'totalFees' => $this->filteredQuery()->sum('fee') - $this->filteredQuery()->sum('discount'),
            'totalOwed' => WalletLoad::query()
                ->where('payment_status', '!=', PaymentStatus::Paid->value)
                ->get()
                ->sum(fn (WalletLoad $load) => $load->amountOwed()),
            'providerTotals' => $this->filteredQuery()
                ->selectRaw('provider, SUM(amount) as total')
                ->groupBy('provider')
                ->orderByDesc('total')
                ->get(),
            'allProviders' => WalletProvider::query()->orderBy('name')->pluck('name'),
            'providersByName' => WalletProvider::query()->get()->keyBy('name'),
            'allShopAccounts' => ShopAccount::query()->orderBy('name')->get(),
            'shopAccountsById' => ShopAccount::query()->get()->keyBy('id'),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'provider', 'shopAccountId', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $loadId, WalletLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(WalletLoad::findOrFail($loadId));
    }

    public function markAsPaid(int $id): void
    {
        $load = WalletLoad::findOrFail($id);

        $load->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $load->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $load = WalletLoad::findOrFail($this->payingId);
        $newAmountPaid = (float) $load->amount_paid + (float) $this->paymentAmount;

        $load->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $load->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Wallet Load History</h1>
            <a href="{{ route('wallet-loads.index') }}" wire:navigate>
                <x-ui.button size="sm">New Wallet Load</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            <x-ui.field label="Provider" name="provider" for="provider" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="provider" id="provider">
                    <option value="">All Providers</option>
                    @foreach ($allProviders as $name)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Send From" name="shopAccountId" for="shopAccountId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="shopAccountId" id="shopAccountId">
                    <option value="">All Accounts</option>
                    @foreach ($allShopAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                    <option value="">All Statuses</option>
                    @foreach ($paymentStatuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $provider || $shopAccountId || $paymentStatusFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex gap-4">
            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Loaded</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalLoaded, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Net Service Fees</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalFees, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Owed by Customers</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOwed, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($providerTotals->isNotEmpty())
        <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($providerTotals as $row)
                <x-ui.stat :label="$row->provider" value="Rs {{ number_format($row->total, 2) }}" />
            @endforeach
        </div>
    @endif

    @if ($loads->isEmpty())
        <x-ui.empty-state
            title="No wallet loads yet"
            description="Completed wallet loads will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('wallet-loads.index') }}" wire:navigate>
                    <x-ui.button>New Wallet Load</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Provider', 'Recipient', 'Amount', 'Fee', 'Discount', 'Total', 'Send From', 'Payment', 'Owed', '']">
            @foreach ($loads as $load)
                <x-ui.table-row wire:key="load-{{ $load->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $load->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$providersByName->get($load->provider)?->imageUrl()" :label="$load->provider" />
                            {{ $load->provider }}
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $load->account_name ?? '—' }}</span>
                            <span class="text-xs text-slate-400">{{ $load->account_number }}</span>
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->fee, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->discount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $shopAccountsById->get($load->shop_account_id)?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($load->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($load->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if ($load->amountOwed() > 0)
                            Rs {{ number_format($load->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if ($load->payment_status->value !== 'paid')
                                <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $load->id }})">
                                    Add Payment
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="markAsPaid({{ $load->id }})"
                                    wire:confirm="Mark this wallet load as fully paid?"
                                >
                                    Mark as Paid
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $load->id }})">
                                Download
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $loads->links() }}
        </div>
    @endif

    <x-ui.modal name="add-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="paymentAmount" for="paymentAmount">
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePaymentForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitPayment">
                    Save Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
