<?php

use App\Actions\CancelRepair;
use App\Actions\UpdateRepair;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\MainCategory;
use App\Models\Repair;
use App\Models\Scopes\ExcludesCancelledScope;
use App\ReportQueries\RepairReport;
use App\Services\RepairReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Repair History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $category = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editCategory = '';
    public string $editSubCategory = '';
    public string $editCustomerId = '';
    public string $editDescription = '';
    public string $editAmount = '';
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

    public function updatingCategory(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): RepairReport
    {
        return new RepairReport($this->from, $this->to, $this->category, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'repairs' => $report->query()->with(['customer', 'mainCategory'])->latest()->paginate(15),
            'totalCollected' => $report->totalCollected(),
            'totalOwed' => $report->totalOwed(),
            'categories' => MainCategory::query()->orderBy('name')->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'category', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $repairId, RepairReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(Repair::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($repairId));
    }

    public function markAsPaid(int $id): void
    {
        $repair = Repair::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($repair->isCancelled(), 403);

        $repair->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $repair->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $repair = Repair::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($repair->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $repair = Repair::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($repair->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $repair->amount_paid + (float) $this->paymentAmount;

        $repair->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $repair->total
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

    public function openEdit(int $repairId): void
    {
        abort_unless(Auth::user()->hasAccessTo('repairs-edit-cancel'), 403);

        $repair = Repair::findOrFail($repairId);

        $this->editingId = $repair->id;
        $this->editCategory = $repair->category;
        $this->editSubCategory = $repair->sub_category ?? '';
        $this->editCustomerId = $repair->customer_id !== null ? (string) $repair->customer_id : '';
        $this->editDescription = $repair->description;
        $this->editAmount = (string) $repair->amount;
        $this->editDiscount = (string) $repair->discount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-repair-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('repairs-edit-cancel'), 403);

        $this->validate([
            'editCategory' => ['required', 'string'],
            'editSubCategory' => ['nullable', 'string', 'max:255'],
            'editCustomerId' => ['nullable', 'integer'],
            'editDescription' => ['required', 'string', 'max:1000'],
            'editAmount' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ]);

        $repair = Repair::findOrFail($this->editingId);

        UpdateRepair::handle($repair, [
            'category' => $this->editCategory,
            'sub_category' => $this->editSubCategory !== '' ? $this->editSubCategory : null,
            'description' => $this->editDescription,
            'amount' => (float) $this->editAmount,
            'discount' => (float) $this->editDiscount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('Repair updated.');
        $this->dispatch('close-modal', name: 'edit-repair-form');
        $this->reset(['editingId', 'editCategory', 'editSubCategory', 'editCustomerId', 'editDescription', 'editAmount', 'editDiscount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-repair-form');
        $this->reset(['editingId', 'editCategory', 'editSubCategory', 'editCustomerId', 'editDescription', 'editAmount', 'editDiscount']);
    }

    public function openCancel(int $repairId): void
    {
        abort_unless(Auth::user()->hasAccessTo('repairs-edit-cancel'), 403);

        $this->cancelingId = $repairId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-repair-form');
    }

    public function cancelRepair(): void
    {
        abort_unless(Auth::user()->hasAccessTo('repairs-edit-cancel'), 403);

        $repair = Repair::findOrFail($this->cancelingId);
        CancelRepair::handle($repair, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-repair-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Repair cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-repair-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Repair History</h1>
            <a href="{{ route('repairs.index') }}" wire:navigate>
                <x-ui.button size="sm">New Repair</x-ui.button>
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

            <x-ui.field label="Category" name="category" for="category" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="category" id="category">
                    <option value="">All Categories</option>
                    @foreach ($categories as $option)
                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
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

            @if ($from || $to || $category || $paymentStatusFilter)
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

    @if ($repairs->isEmpty())
        <x-ui.empty-state
            title="No repairs yet"
            description="Completed repairs will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('repairs.index') }}" wire:navigate>
                    <x-ui.button>New Repair</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Category', 'Description', 'Customer', 'Total', 'Payment', 'Owed', '']">
            @foreach ($repairs as $repair)
                <x-ui.table-row wire:key="repair-{{ $repair->id }}" @class(['opacity-50' => $repair->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $repair->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->categoryLabel() }}</x-ui.table-cell>
                    <x-ui.table-cell class="max-w-xs truncate">{{ $repair->description }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $repair->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($repair->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($repair->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($repair->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($repair->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if (! $repair->isCancelled() && $repair->amountOwed() > 0)
                            Rs {{ number_format($repair->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if (! $repair->isCancelled())
                                @if ($repair->payment_status->value !== 'paid')
                                    <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $repair->id }})">
                                        Add Payment
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="markAsPaid({{ $repair->id }})"
                                        wire:confirm="Mark this repair as fully paid?"
                                    >
                                        Mark as Paid
                                    </x-ui.button>
                                @endif
                                @if (auth()->user()->hasAccessTo('repairs-edit-cancel'))
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $repair->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $repair->id }})" class="text-red-600 hover:bg-red-50">
                                        Delete
                                    </x-ui.button>
                                @endif
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $repair->id }})">
                                Download
                            </x-ui.button>
                        </div>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $repairs->links() }}
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

    <x-ui.modal name="edit-repair-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Edit Repair</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Category" name="editCategory" for="editCategory">
                    <x-ui.select wire:model="editCategory" id="editCategory">
                        @foreach ($categories as $option)
                            <option value="{{ $option->slug }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Sub-Category" name="editSubCategory" for="editSubCategory" help="Optional">
                    <x-ui.input wire:model="editSubCategory" id="editSubCategory" />
                </x-ui.field>

                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Optional — leave blank for a walk-in customer">
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

                <x-ui.field label="Discount" name="editDiscount" for="editDiscount" help="Optional">
                    <x-ui.input wire:model="editDiscount" id="editDiscount" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-4">
                <x-ui.field label="Description" name="editDescription" for="editDescription">
                    <textarea
                        wire:model="editDescription"
                        id="editDescription"
                        rows="3"
                        class="block w-full rounded-lg border-slate-300 py-2.5 px-3.5 text-base text-slate-900 shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500"
                    ></textarea>
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

    <x-ui.modal name="cancel-repair-form" max-width="sm">
        <form wire:submit="cancelRepair" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Repair?</h2>
            <p class="mt-2 text-sm text-slate-600">
                This repair will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Repair
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelRepair" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Repair
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
