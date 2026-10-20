<?php

use App\Actions\CancelServicePayment;
use App\Actions\UpdateServicePayment;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\ServiceCategory;
use App\Models\ServiceItem;
use App\Models\ServicePayment;
use App\ReportQueries\ServiceReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Service History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $serviceCategoryId = '';
    public string $serviceItemId = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editServiceCategoryId = '';
    public string $editServiceItemId = '';
    public string $editCustomerId = '';
    public string $editAmount = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->services_enabled, 403);
    }

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingServiceCategoryId(): void
    {
        $this->serviceItemId = '';
        $this->resetPage();
    }

    public function updatingServiceItemId(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): ServiceReport
    {
        return new ServiceReport($this->from, $this->to, $this->serviceCategoryId, $this->serviceItemId, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'payments' => $report->query()->with(['serviceCategory', 'serviceItem', 'customer'])->latest()->paginate(15),
            'totalCollected' => $report->totalCollected(),
            'totalOwed' => $report->totalOwed(),
            'allCategories' => ServiceCategory::query()->orderBy('name')->get(),
            'allItems' => ServiceItem::query()
                ->when($this->serviceCategoryId, fn ($query) => $query->where('service_category_id', $this->serviceCategoryId))
                ->orderBy('name')
                ->get(),
            'editItems' => ServiceItem::query()
                ->when($this->editServiceCategoryId, fn ($query) => $query->where('service_category_id', $this->editServiceCategoryId))
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'serviceCategoryId', 'serviceItemId', 'paymentStatusFilter']);
    }

    public function markAsPaid(int $id): void
    {
        $payment = ServicePayment::findOrFail($id);
        abort_if($payment->isCancelled(), 403);

        $payment->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $payment->amount,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $payment = ServicePayment::findOrFail($id);
        abort_if($payment->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $payment = ServicePayment::findOrFail($this->payingId);
        abort_if($payment->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $payment->amount_paid + (float) $this->paymentAmount;

        $payment->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $payment->amount
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

    public function openEdit(int $paymentId): void
    {
        abort_unless(Auth::user()->hasAccessTo('services-edit-cancel'), 403);

        $payment = ServicePayment::findOrFail($paymentId);

        $this->editingId = $payment->id;
        $this->editServiceCategoryId = (string) $payment->service_category_id;
        $this->editServiceItemId = (string) $payment->service_item_id;
        $this->editCustomerId = $payment->customer_id !== null ? (string) $payment->customer_id : '';
        $this->editAmount = (string) $payment->amount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-service-payment-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('services-edit-cancel'), 403);

        $this->validate([
            'editServiceCategoryId' => ['required', 'integer'],
            'editServiceItemId' => ['required', 'integer'],
            'editCustomerId' => ['nullable', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = ServicePayment::findOrFail($this->editingId);

        UpdateServicePayment::handle($payment, [
            'service_category_id' => (int) $this->editServiceCategoryId,
            'service_item_id' => (int) $this->editServiceItemId,
            'amount' => (float) $this->editAmount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('Service updated.');
        $this->dispatch('close-modal', name: 'edit-service-payment-form');
        $this->reset(['editingId', 'editServiceCategoryId', 'editServiceItemId', 'editCustomerId', 'editAmount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-service-payment-form');
        $this->reset(['editingId', 'editServiceCategoryId', 'editServiceItemId', 'editCustomerId', 'editAmount']);
    }

    public function openCancel(int $paymentId): void
    {
        abort_unless(Auth::user()->hasAccessTo('services-edit-cancel'), 403);

        $this->cancelingId = $paymentId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-service-payment-form');
    }

    public function cancelPayment(): void
    {
        abort_unless(Auth::user()->hasAccessTo('services-edit-cancel'), 403);

        $payment = ServicePayment::findOrFail($this->cancelingId);
        CancelServicePayment::handle($payment, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-service-payment-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Service cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-service-payment-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Service History</h1>
            <a href="{{ route('services.index') }}" wire:navigate>
                <x-ui.button size="sm">New Service</x-ui.button>
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

            <x-ui.field label="Category" name="serviceCategoryId" for="serviceCategoryId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="serviceCategoryId" id="serviceCategoryId">
                    <option value="">All Categories</option>
                    @foreach ($allCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Item" name="serviceItemId" for="serviceItemId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="serviceItemId" id="serviceItemId">
                    <option value="">All Items</option>
                    @foreach ($allItems as $item)
                        <option value="{{ $item->id }}">{{ $item->name }}</option>
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

            @if ($from || $to || $serviceCategoryId || $serviceItemId || $paymentStatusFilter)
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

    @if ($payments->isEmpty())
        <x-ui.empty-state
            title="No services logged yet"
            description="Completed services will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('services.index') }}" wire:navigate>
                    <x-ui.button>New Service</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Category', 'Item', 'Customer', 'Amount', 'Payment', 'Owed', '']">
            @foreach ($payments as $payment)
                <x-ui.table-row wire:key="service-payment-{{ $payment->id }}" @class(['opacity-50' => $payment->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $payment->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->serviceCategory?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->serviceItem?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($payment->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($payment->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($payment->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($payment->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if (! $payment->isCancelled() && $payment->amountOwed() > 0)
                            Rs {{ number_format($payment->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if (! $payment->isCancelled())
                                @if ($payment->payment_status->value !== 'paid')
                                    <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $payment->id }})">
                                        Add Payment
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="markAsPaid({{ $payment->id }})"
                                        wire:confirm="Mark this service as fully paid?"
                                    >
                                        Mark as Paid
                                    </x-ui.button>
                                @endif
                                @if (auth()->user()->hasAccessTo('services-edit-cancel'))
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $payment->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $payment->id }})" class="text-red-600 hover:bg-red-50">
                                        Delete
                                    </x-ui.button>
                                @endif
                            @endif
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $payments->links() }}
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

    <x-ui.modal name="edit-service-payment-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Edit Service</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Category" name="editServiceCategoryId" for="editServiceCategoryId">
                    <x-ui.select wire:model.live="editServiceCategoryId" id="editServiceCategoryId">
                        @foreach ($allCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Item" name="editServiceItemId" for="editServiceItemId">
                    <x-ui.select wire:model="editServiceItemId" id="editServiceItemId">
                        @foreach ($editItems as $item)
                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                        @endforeach
                    </x-ui.select>
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
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="0" step="0.01" />
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

    <x-ui.modal name="cancel-service-payment-form" max-width="sm">
        <form wire:submit="cancelPayment" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Service?</h2>
            <p class="mt-2 text-sm text-slate-600">
                This service will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Service
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelPayment" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Service
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
