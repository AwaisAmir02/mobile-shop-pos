<?php

use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\NadraVerification;
use App\Services\NadraVerificationReceiptPdfService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('NADRA Verification History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
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

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function filteredQuery()
    {
        return NadraVerification::query()
            ->when($this->from, fn ($query) => $query->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($query) => $query->whereDate('created_at', '<=', $this->to))
            ->when($this->paymentStatusFilter, fn ($query) => $query->where('payment_status', $this->paymentStatusFilter));
    }

    public function with(): array
    {
        return [
            'verifications' => $this->filteredQuery()->with('customer')->latest()->paginate(15),
            'totalCollected' => $this->filteredQuery()->sum('total'),
            'totalOwed' => NadraVerification::query()
                ->where('payment_status', '!=', PaymentStatus::Paid->value)
                ->get()
                ->sum(fn (NadraVerification $verification) => $verification->amountOwed()),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $verificationId, NadraVerificationReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(NadraVerification::findOrFail($verificationId));
    }

    public function markAsPaid(int $id): void
    {
        $verification = NadraVerification::findOrFail($id);

        $verification->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $verification->total,
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

        $verification = NadraVerification::findOrFail($this->payingId);
        $newAmountPaid = (float) $verification->amount_paid + (float) $this->paymentAmount;

        $verification->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $verification->total
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
            <h1 class="text-xl font-semibold text-slate-900">NADRA Verification History</h1>
            <a href="{{ route('nadra-verifications.index') }}" wire:navigate>
                <x-ui.button size="sm">New Verification</x-ui.button>
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

            <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                    <option value="">All Statuses</option>
                    @foreach ($paymentStatuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if ($from || $to || $paymentStatusFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex gap-4">
            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Collected</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalCollected, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Owed by Customers</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOwed, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($verifications->isEmpty())
        <x-ui.empty-state
            title="No NADRA verifications yet"
            description="Completed verifications will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('nadra-verifications.index') }}" wire:navigate>
                    <x-ui.button>New Verification</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Phone', 'CNIC', 'Customer', 'Total', 'Payment', 'Owed', '']">
            @foreach ($verifications as $verification)
                <x-ui.table-row wire:key="nadra-verification-{{ $verification->id }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $verification->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->phone_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->cnic_number }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $verification->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($verification->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($verification->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($verification->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if ($verification->amountOwed() > 0)
                            Rs {{ number_format($verification->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if ($verification->payment_status->value !== 'paid')
                                <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $verification->id }})">
                                    Add Payment
                                </x-ui.button>
                                <x-ui.button
                                    size="sm"
                                    variant="ghost"
                                    wire:click="markAsPaid({{ $verification->id }})"
                                    wire:confirm="Mark this verification as fully paid?"
                                >
                                    Mark as Paid
                                </x-ui.button>
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $verification->id }})">
                                Download
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $verifications->links() }}
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
