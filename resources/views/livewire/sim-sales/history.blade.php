<?php

use App\Actions\CancelSimSale;
use App\Actions\UpdateSimSale;
use App\Enums\PaymentStatus;
use App\Enums\SimForm;
use App\Enums\SimType;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\Network;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\SimSale;
use App\ReportQueries\SimSaleReport;
use App\Services\SimSaleReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('SIM Sale History')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $network = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editNetwork = '';
    public string $editSimType = '';
    public string $editSimForm = '';
    public bool $editIsDuplicate = false;
    public string $editSimNumber = '';
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

    public function updatingNetwork(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): SimSaleReport
    {
        return new SimSaleReport($this->from, $this->to, $this->network, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'sales' => $report->query()->with('customer')->latest()->paginate(15),
            'totalRevenue' => $report->totalRevenue(),
            'totalOwed' => $report->totalOwed(),
            'allNetworks' => Network::query()->orderBy('name')->pluck('name'),
            'networksByName' => Network::query()->get()->keyBy('name'),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
            'simTypes' => SimType::cases(),
            'simForms' => SimForm::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'network', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $saleId, SimSaleReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($saleId));
    }

    public function markAsPaid(int $id): void
    {
        $sale = SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($sale->isCancelled(), 403);

        $sale->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $sale->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $sale = SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($sale->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $sale = SimSale::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($sale->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $sale->amount_paid + (float) $this->paymentAmount;

        $sale->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $sale->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);
        $this->notePaymentSource($sale, (float) $this->paymentAmount);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function openEdit(int $saleId): void
    {
        abort_unless(Auth::user()->hasAccessTo('sim-sales-edit-cancel'), 403);

        $sale = SimSale::findOrFail($saleId);

        $this->editingId = $sale->id;
        $this->editNetwork = $sale->network;
        $this->editSimType = $sale->sim_type->value;
        $this->editSimForm = $sale->sim_form->value;
        $this->editIsDuplicate = $sale->is_duplicate;
        $this->editSimNumber = $sale->sim_number;
        $this->editCustomerId = $sale->customer_id !== null ? (string) $sale->customer_id : '';
        $this->editAmount = (string) $sale->amount;
        $this->editFee = (string) $sale->fee;
        $this->editDiscount = (string) $sale->discount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-sim-sale-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sim-sales-edit-cancel'), 403);

        $this->validate([
            'editNetwork' => ['required', 'string', 'max:255'],
            'editSimType' => ['required', 'string'],
            'editSimForm' => ['required', 'string'],
            'editSimNumber' => ['required', 'string', 'max:255'],
            'editCustomerId' => ['nullable', 'integer'],
            'editAmount' => ['required', 'numeric', 'min:0'],
            'editFee' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ]);

        $sale = SimSale::findOrFail($this->editingId);

        UpdateSimSale::handle($sale, [
            'network' => $this->editNetwork,
            'sim_type' => $this->editSimType,
            'sim_form' => $this->editSimForm,
            'is_duplicate' => $this->editIsDuplicate,
            'sim_number' => $this->editSimNumber,
            'amount' => (float) $this->editAmount,
            'fee' => (float) $this->editFee,
            'discount' => (float) $this->editDiscount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('SIM sale updated.');
        $this->dispatch('close-modal', name: 'edit-sim-sale-form');
        $this->reset(['editingId', 'editNetwork', 'editSimType', 'editSimForm', 'editIsDuplicate', 'editSimNumber', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-sim-sale-form');
        $this->reset(['editingId', 'editNetwork', 'editSimType', 'editSimForm', 'editIsDuplicate', 'editSimNumber', 'editCustomerId', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function openCancel(int $saleId): void
    {
        abort_unless(Auth::user()->hasAccessTo('sim-sales-edit-cancel'), 403);

        $this->cancelingId = $saleId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-sim-sale-form');
    }

    public function cancelSale(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sim-sales-edit-cancel'), 403);

        $sale = SimSale::findOrFail($this->cancelingId);
        CancelSimSale::handle($sale, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-sim-sale-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('SIM sale cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-sim-sale-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>
            SIM Sale History
            <x-slot:actions>
                <x-ui.button href="{{ route('sim-sales.index') }}" wire:navigate size="sm">New SIM Sale</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to) + (int) filled($network) + (int) filled($paymentStatusFilter)">
        <x-ui.date-range />

        <x-ui.field label="Network" name="network" for="network" class="w-full sm:w-44">
            <x-ui.select wire:model.live="network" id="network">
                <option value="">All Networks</option>
                @foreach ($allNetworks as $name)
                    <option value="{{ $name }}">{{ $name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="w-full sm:w-44">
            <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                <option value="">All Statuses</option>
                @foreach ($paymentStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        @if ($from || $to || $network || $paymentStatusFilter)
            <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                    Clear
                </x-ui.button>
        @endif
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Total Revenue" value="Rs {{ number_format($totalRevenue, 2) }}" />
        <x-ui.stat label="Total Owed by Customers" value="Rs {{ number_format($totalOwed, 2) }}" />
    </x-ui.stat-row>

    @if ($sales->isEmpty())
        <x-ui.empty-state
            title="No SIM sales yet"
            description="Completed SIM sales will show up here."
        >
            <x-slot name="action">
                <x-ui.button href="{{ route('sim-sales.index') }}" wire:navigate>New SIM Sale</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table stack :headers="['Receipt', 'Network', 'Plan', 'SIM Number', 'Customer', 'Total', 'Payment', '']">
            @foreach ($sales as $sale)
                <x-ui.table-row wire:key="sim-sale-{{ $sale->id }}" @class(['opacity-50' => $sale->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $sale->receiptNumber() }}<span class="ui-sub">{{ $sale->created_at->format('d M Y, h:i A') }}</span></x-ui.table-cell>
                    <x-ui.table-cell label="Network">
                        @php $network = $networksByName->get($sale->network); @endphp
                        <div class="flex items-center gap-2">
                            <x-ui.thumbnail :src="$network?->imageUrl()" :label="$sale->network" :color="$network?->color" />
                            {{ $sale->network }}
                            <x-ui.color-dot :color="$network?->color" />
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Plan">
                        <div class="flex flex-col">
                            <span class="text-ink-muted">{{ $sale->sim_type->label() }} · {{ $sale->sim_form->label() }}</span>
                            @if ($sale->is_duplicate)
                                <span><x-ui.badge variant="warning">Duplicate</x-ui.badge></span>
                            @endif
                        </div>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="SIM Number">{{ $sale->sim_number }}</x-ui.table-cell>
                    <x-ui.table-cell label="Customer">{{ $sale->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Total" class="font-semibold text-ink">Rs {{ number_format($sale->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($sale->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($sale->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($sale->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                        @if (! $sale->isCancelled() && $sale->amountOwed() > 0)
                            <span class="ui-sub font-semibold text-ink">Rs {{ number_format($sale->amountOwed(), 2) }} owed</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $sale->receiptNumber() }}">
                            @if (! $sale->isCancelled() && $sale->payment_status->value !== 'paid')
                                <x-ui.menu-item wire:click="openAddPayment({{ $sale->id }})">Add Payment</x-ui.menu-item>
                                <x-ui.menu-item wire:click="markAsPaid({{ $sale->id }})" confirm="Mark this SIM sale as fully paid?" confirm-title="Mark as paid?" confirm-label="Mark as Paid">Mark as Paid</x-ui.menu-item>
                            @endif
                            <x-ui.menu-item wire:click="downloadReceipt({{ $sale->id }})">Download</x-ui.menu-item>
                            @if (! $sale->isCancelled() && auth()->user()->hasAccessTo('sim-sales-edit-cancel'))
                                <x-ui.menu-item wire:click="openEdit({{ $sale->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="openCancel({{ $sale->id }})">Delete</x-ui.menu-item>
                            @endif
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $sales->links() }}
        </div>
    @endif

    <x-ui.modal name="add-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Add Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="paymentAmount" for="paymentAmount">
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
                <x-ledger.paid-from :value="$paidFrom" label="Received Into" />
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

    <x-ui.modal name="edit-sim-sale-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Edit SIM Sale</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Network" name="editNetwork" for="editNetwork">
                    <x-ui.select wire:model="editNetwork" id="editNetwork">
                        @foreach ($allNetworks as $name)
                            <option value="{{ $name }}">{{ $name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="SIM Type" name="editSimType" for="editSimType">
                    <x-ui.select wire:model="editSimType" id="editSimType">
                        @foreach ($simTypes as $type)
                            <option value="{{ $type->value }}">{{ $type->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="SIM Form" name="editSimForm" for="editSimForm">
                    <x-ui.select wire:model="editSimForm" id="editSimForm">
                        @foreach ($simForms as $form)
                            <option value="{{ $form->value }}">{{ $form->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="SIM Number" name="editSimNumber" for="editSimNumber">
                    <x-ui.input wire:model="editSimNumber" id="editSimNumber" />
                </x-ui.field>

                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Leave blank for a walk-in">
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

                <x-ui.field label="Fee" name="editFee" for="editFee">
                    <x-ui.input wire:model="editFee" id="editFee" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Discount" name="editDiscount" for="editDiscount">
                    <x-ui.input wire:model="editDiscount" id="editDiscount" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-4">
                <x-ui.checkbox wire:model="editIsDuplicate" label="This is a duplicate SIM" />
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

    <x-ui.modal name="cancel-sim-sale-form" max-width="sm">
        <form wire:submit="cancelSale" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This SIM Sale?</h2>
            <p class="mt-2 text-sm text-ink-muted">
                This SIM sale will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep SIM Sale
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelSale" variant="danger">
                    Cancel SIM Sale
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
