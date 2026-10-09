<?php

use App\Actions\CancelBalanceLoad;
use App\Actions\UpdateBalanceLoad;
use App\Enums\BalanceLoadType;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\BalanceLoad;
use App\Models\Customer;
use App\Models\Network;
use App\Models\Scopes\ExcludesCancelledScope;
use App\ReportQueries\BalanceLoadReport;
use App\Services\BalanceLoadReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Balance Load History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editNetwork = '';
    public string $editLoadType = '';
    public string $editPhoneNumber = '';
    public string $editCustomerId = '';
    public string $editAmount = '';
    public string $editFee = '';
    public string $editDiscount = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

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

    protected function report(): BalanceLoadReport
    {
        return new BalanceLoadReport($this->from, $this->to, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'loads' => $report->query()->with('customer')->latest()->paginate(15),
            'totalLoaded' => $report->totalLoaded(),
            'totalFees' => $report->totalFees(),
            'totalOwed' => $report->totalOwed(),
            'networksByName' => Network::query()->get()->keyBy('name'),
            'networks' => Network::query()->orderBy('name')->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
            'loadTypes' => BalanceLoadType::cases(),
        ];
    }

    public function clearDateFilters(): void
    {
        $this->reset(['from', 'to', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $loadId, BalanceLoadReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($loadId));
    }

    public function markAsPaid(int $id): void
    {
        $load = BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($load->isCancelled(), 403);

        $load->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $load->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $load = BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($load->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $load = BalanceLoad::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($load->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

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

    public function openEdit(int $loadId): void
    {
        abort_unless(Auth::user()->hasAccessTo('balance-loads-edit-cancel'), 403);

        $load = BalanceLoad::findOrFail($loadId);

        $this->editingId = $load->id;
        $this->editNetwork = $load->network;
        $this->editLoadType = $load->load_type->value;
        $this->editPhoneNumber = $load->phone_number ?? '';
        $this->editCustomerId = $load->customer_id !== null ? (string) $load->customer_id : '';
        $this->editAmount = (string) $load->amount;
        $this->editFee = (string) $load->fee;
        $this->editDiscount = (string) $load->discount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-balance-load-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('balance-loads-edit-cancel'), 403);

        $this->validate([
            'editNetwork' => ['required', 'string', 'max:255'],
            'editLoadType' => ['required', 'string'],
            'editPhoneNumber' => ['nullable', 'string', 'max:20'],
            'editCustomerId' => ['nullable', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:1'],
            'editFee' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ]);

        $load = BalanceLoad::findOrFail($this->editingId);

        UpdateBalanceLoad::handle($load, [
            'network' => $this->editNetwork,
            'load_type' => $this->editLoadType,
            'phone_number' => $this->editPhoneNumber !== '' ? $this->editPhoneNumber : null,
            'amount' => (float) $this->editAmount,
            'fee' => (float) $this->editFee,
            'discount' => (float) $this->editDiscount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('Balance load updated.');
        $this->dispatch('close-modal', name: 'edit-balance-load-form');
        $this->reset(['editingId', 'editNetwork', 'editLoadType', 'editPhoneNumber', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-balance-load-form');
        $this->reset(['editingId', 'editNetwork', 'editLoadType', 'editPhoneNumber', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function openCancel(int $loadId): void
    {
        abort_unless(Auth::user()->hasAccessTo('balance-loads-edit-cancel'), 403);

        $this->cancelingId = $loadId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-balance-load-form');
    }

    public function cancelLoad(): void
    {
        abort_unless(Auth::user()->hasAccessTo('balance-loads-edit-cancel'), 403);

        $load = BalanceLoad::findOrFail($this->cancelingId);
        CancelBalanceLoad::handle($load, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-balance-load-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Balance load cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-balance-load-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Balance Load History</h1>
            <a href="{{ route('balance-loads.index') }}" wire:navigate>
                <x-ui.button size="sm">New Balance Load</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
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
                    <x-ui.button type="button" variant="ghost" wire:click="clearDateFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4 sm:flex-row">
            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Loaded</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalLoaded, 2) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false" class="w-full sm:w-auto">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Fees</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalFees, 2) }}</p>
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

    @if ($loads->isEmpty())
        <x-ui.empty-state
            title="No balance loads yet"
            description="Completed balance loads will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('balance-loads.index') }}" wire:navigate>
                    <x-ui.button>New Balance Load</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Network', 'Type', 'Phone', 'Customer', 'Amount', 'Fee', 'Discount', 'Total', 'Payment', 'Owed', '']">
            @foreach ($loads as $load)
                <x-ui.table-row wire:key="load-{{ $load->id }}" @class(['opacity-50' => $load->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $load->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @php $network = $networksByName->get($load->network); @endphp
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$network?->imageUrl()" :label="$load->network" :color="$network?->color" />
                            {{ $load->network }}
                            <x-ui.color-dot :color="$network?->color" />
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->load_type->label() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->phone_number ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $load->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->fee, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($load->discount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($load->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($load->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($load->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($load->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if (! $load->isCancelled() && $load->amountOwed() > 0)
                            Rs {{ number_format($load->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if (! $load->isCancelled())
                                @if ($load->payment_status->value !== 'paid')
                                    <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $load->id }})">
                                        Add Payment
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="markAsPaid({{ $load->id }})"
                                        wire:confirm="Mark this balance load as fully paid?"
                                    >
                                        Mark as Paid
                                    </x-ui.button>
                                @endif
                                @if (auth()->user()->hasAccessTo('balance-loads-edit-cancel'))
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $load->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $load->id }})" class="text-red-600 hover:bg-red-50">
                                        Delete
                                    </x-ui.button>
                                @endif
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

    <x-ui.modal name="edit-balance-load-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Edit Balance Load</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Network" name="editNetwork" for="editNetwork">
                    <x-ui.select wire:model="editNetwork" id="editNetwork">
                        @foreach ($networks as $network)
                            <option value="{{ $network->name }}">{{ $network->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Type" name="editLoadType" for="editLoadType">
                    <x-ui.select wire:model="editLoadType" id="editLoadType">
                        @foreach ($loadTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Phone Number" name="editPhoneNumber" for="editPhoneNumber" help="Optional">
                    <x-ui.input wire:model="editPhoneNumber" id="editPhoneNumber" />
                </x-ui.field>

                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Optional — leave blank for a walk-in">
                    <x-ui.select wire:model="editCustomerId" id="editCustomerId">
                        <option value="">Walk-in (no customer)</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Amount" name="editAmount" for="editAmount">
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="1" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Service Charge" name="editFee" for="editFee">
                    <x-ui.input wire:model="editFee" id="editFee" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Discount" name="editDiscount" for="editDiscount" help="Optional">
                    <x-ui.input wire:model="editDiscount" id="editDiscount" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEdit">
                    Save Changes
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-balance-load-form" max-width="sm">
        <form wire:submit="cancelLoad" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Balance Load?</h2>
            <p class="mt-2 text-sm text-slate-600">
                This balance load will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Balance Load
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelLoad" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Balance Load
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
