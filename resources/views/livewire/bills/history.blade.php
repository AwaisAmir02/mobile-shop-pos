<?php

use App\Actions\CancelBillPayment;
use App\Actions\UpdateBillPayment;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\BillCategory;
use App\Models\BillPayment;
use App\Models\BillProvider;
use App\Models\Customer;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\ShopAccount;
use App\ReportQueries\BillReport;
use App\Services\BillPaymentReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Bill Payment History')] class extends Component
{
    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $billCategoryId = '';
    public string $billProviderId = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editBillCategoryId = '';
    public string $editBillProviderId = '';
    public string $editShopAccountId = '';
    public string $editConsumerNumber = '';
    public string $editConsumerName = '';
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

    public function updatingBillCategoryId(): void
    {
        $this->billProviderId = '';
        $this->resetPage();
    }

    public function updatingBillProviderId(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): BillReport
    {
        return new BillReport($this->from, $this->to, $this->billCategoryId, $this->billProviderId, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'payments' => $report->query()->with(['billCategory', 'billProvider', 'customer', 'shopAccount'])->latest()->paginate(15),
            'totalCollected' => $report->totalCollected(),
            'totalOwed' => $report->totalOwed(),
            'allCategories' => BillCategory::query()->orderBy('name')->get(),
            'allProviders' => BillProvider::query()
                ->when($this->billCategoryId, fn ($query) => $query->where('bill_category_id', $this->billCategoryId))
                ->orderBy('name')
                ->get(),
            'editProviders' => BillProvider::query()
                ->when($this->editBillCategoryId, fn ($query) => $query->where('bill_category_id', $this->editBillCategoryId))
                ->orderBy('name')
                ->get(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'shopAccounts' => ShopAccount::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'billCategoryId', 'billProviderId', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $paymentId, BillPaymentReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($paymentId));
    }

    public function markAsPaid(int $id): void
    {
        $payment = BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($payment->isCancelled(), 403);

        $payment->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $payment->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $payment = BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($payment->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $payment = BillPayment::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($payment->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $payment->amount_paid + (float) $this->paymentAmount;

        $payment->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $payment->total
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
        abort_unless(Auth::user()->hasAccessTo('bills-edit-cancel'), 403);

        $payment = BillPayment::findOrFail($paymentId);

        $this->editingId = $payment->id;
        $this->editBillCategoryId = (string) $payment->bill_category_id;
        $this->editBillProviderId = (string) $payment->bill_provider_id;
        $this->editShopAccountId = (string) $payment->shop_account_id;
        $this->editConsumerNumber = $payment->consumer_number;
        $this->editConsumerName = $payment->consumer_name;
        $this->editCustomerId = $payment->customer_id !== null ? (string) $payment->customer_id : '';
        $this->editAmount = (string) $payment->amount;
        $this->editFee = (string) $payment->fee;
        $this->editDiscount = (string) $payment->discount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-bill-payment-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('bills-edit-cancel'), 403);

        $this->validate([
            'editBillCategoryId' => ['required', 'integer'],
            'editBillProviderId' => ['required', 'integer'],
            'editShopAccountId' => ['required', 'integer'],
            'editConsumerNumber' => ['required', 'string', 'max:100'],
            'editConsumerName' => ['required', 'string', 'max:255'],
            'editCustomerId' => ['nullable', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:0'],
            'editFee' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = BillPayment::findOrFail($this->editingId);

        UpdateBillPayment::handle($payment, [
            'bill_category_id' => (int) $this->editBillCategoryId,
            'bill_provider_id' => (int) $this->editBillProviderId,
            'shop_account_id' => (int) $this->editShopAccountId,
            'consumer_number' => $this->editConsumerNumber,
            'consumer_name' => $this->editConsumerName,
            'amount' => (float) $this->editAmount,
            'fee' => (float) $this->editFee,
            'discount' => (float) $this->editDiscount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('Bill payment updated.');
        $this->dispatch('close-modal', name: 'edit-bill-payment-form');
        $this->reset(['editingId', 'editBillCategoryId', 'editBillProviderId', 'editShopAccountId', 'editConsumerNumber', 'editConsumerName', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-bill-payment-form');
        $this->reset(['editingId', 'editBillCategoryId', 'editBillProviderId', 'editShopAccountId', 'editConsumerNumber', 'editConsumerName', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function openCancel(int $paymentId): void
    {
        abort_unless(Auth::user()->hasAccessTo('bills-edit-cancel'), 403);

        $this->cancelingId = $paymentId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-bill-payment-form');
    }

    public function cancelPayment(): void
    {
        abort_unless(Auth::user()->hasAccessTo('bills-edit-cancel'), 403);

        $payment = BillPayment::findOrFail($this->cancelingId);
        CancelBillPayment::handle($payment, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-bill-payment-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Bill payment cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-bill-payment-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Bill Payment History</h1>
            <a href="{{ route('bills.index') }}" wire:navigate>
                <x-ui.button size="sm">New Bill Payment</x-ui.button>
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

            <x-ui.field label="Category" name="billCategoryId" for="billCategoryId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="billCategoryId" id="billCategoryId">
                    <option value="">All Categories</option>
                    @foreach ($allCategories as $category)
                        <option value="{{ $category->id }}">{{ $category->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Provider" name="billProviderId" for="billProviderId" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="billProviderId" id="billProviderId">
                    <option value="">All Providers</option>
                    @foreach ($allProviders as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
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

            @if ($from || $to || $billCategoryId || $billProviderId || $paymentStatusFilter)
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
            title="No bill payments yet"
            description="Completed bill payments will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('bills.index') }}" wire:navigate>
                    <x-ui.button>New Bill Payment</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table :headers="['Receipt', 'Date', 'Category', 'Provider', 'Consumer', 'Customer', 'Send From', 'Total', 'Payment', 'Owed', '']">
            @foreach ($payments as $payment)
                <x-ui.table-row wire:key="bill-payment-{{ $payment->id }}" @class(['opacity-50' => $payment->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $payment->receiptNumber() }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->created_at->format('d M Y, h:i A') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->billCategory?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->billProvider?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <div class="flex flex-col">
                            <span class="text-slate-700">{{ $payment->consumer_name }}</span>
                            <span class="text-xs text-slate-400">{{ $payment->consumer_number }}</span>
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $payment->shopAccount?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($payment->total, 2) }}</x-ui.table-cell>
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
                                        wire:confirm="Mark this bill payment as fully paid?"
                                    >
                                        Mark as Paid
                                    </x-ui.button>
                                @endif
                                @if (auth()->user()->hasAccessTo('bills-edit-cancel'))
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $payment->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $payment->id }})" class="text-red-600 hover:bg-red-50">
                                        Delete
                                    </x-ui.button>
                                @endif
                            @endif
                            <x-ui.button size="sm" variant="ghost" wire:click="downloadReceipt({{ $payment->id }})">
                                Download
                            </x-ui.button>
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

    <x-ui.modal name="edit-bill-payment-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Edit Bill Payment</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Category" name="editBillCategoryId" for="editBillCategoryId">
                    <x-ui.select wire:model.live="editBillCategoryId" id="editBillCategoryId">
                        @foreach ($allCategories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Provider" name="editBillProviderId" for="editBillProviderId">
                    <x-ui.select wire:model="editBillProviderId" id="editBillProviderId">
                        @foreach ($editProviders as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Consumer Number" name="editConsumerNumber" for="editConsumerNumber">
                    <x-ui.input wire:model="editConsumerNumber" id="editConsumerNumber" />
                </x-ui.field>

                <x-ui.field label="Consumer Name" name="editConsumerName" for="editConsumerName">
                    <x-ui.input wire:model="editConsumerName" id="editConsumerName" />
                </x-ui.field>

                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Optional — leave blank for a walk-in payment">
                    <x-ui.select wire:model="editCustomerId" id="editCustomerId">
                        <option value="">Walk-in (no customer)</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Send From" name="editShopAccountId" for="editShopAccountId">
                    <x-ui.select wire:model="editShopAccountId" id="editShopAccountId">
                        @foreach ($shopAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Bill Amount" name="editAmount" for="editAmount">
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="0" step="0.01" />
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

    <x-ui.modal name="cancel-bill-payment-form" max-width="sm">
        <form wire:submit="cancelPayment" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Bill Payment?</h2>
            <p class="mt-2 text-sm text-slate-600">
                This bill payment will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Bill Payment
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelPayment" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Bill Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
