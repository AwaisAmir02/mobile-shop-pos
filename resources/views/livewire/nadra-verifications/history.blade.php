<?php

use App\Actions\CancelNadraVerification;
use App\Actions\UpdateNadraVerification;
use App\Enums\PaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\NadraVerification;
use App\Models\Scopes\ExcludesCancelledScope;
use App\ReportQueries\NadraVerificationReport;
use App\Services\NadraVerificationReceiptPdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('NADRA Verification History')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $paymentStatusFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editCustomerId = '';
    public string $editPhoneNumber = '';
    public string $editCnicNumber = '';
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

    protected function report(): NadraVerificationReport
    {
        return new NadraVerificationReport($this->from, $this->to, $this->paymentStatusFilter, includeCancelled: true);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'verifications' => $report->query()->with('customer')->latest()->paginate(15),
            'totalCollected' => $report->totalCollected(),
            'totalOwed' => $report->totalOwed(),
            'customers' => Customer::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
        ];
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'paymentStatusFilter']);
    }

    public function downloadReceipt(int $verificationId, NadraVerificationReceiptPdfService $pdf): StreamedResponse
    {
        return $pdf->download(NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($verificationId));
    }

    public function markAsPaid(int $id): void
    {
        $verification = NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($verification->isCancelled(), 403);

        $verification->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $verification->total,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $verification = NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($verification->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $verification = NadraVerification::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($verification->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $verification->amount_paid + (float) $this->paymentAmount;

        $verification->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $newAmountPaid >= (float) $verification->total
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);
        $this->notePaymentSource($verification, (float) $this->paymentAmount);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function openEdit(int $verificationId): void
    {
        abort_unless(Auth::user()->hasAccessTo('nadra-verifications-edit-cancel'), 403);

        $verification = NadraVerification::findOrFail($verificationId);

        $this->editingId = $verification->id;
        $this->editCustomerId = $verification->customer_id !== null ? (string) $verification->customer_id : '';
        $this->editPhoneNumber = $verification->phone_number;
        $this->editCnicNumber = $verification->cnic_number;
        $this->editAmount = (string) $verification->amount;
        $this->editFee = (string) $verification->fee;
        $this->editDiscount = (string) $verification->discount;
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-verification-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('nadra-verifications-edit-cancel'), 403);

        $this->validate([
            'editCustomerId' => ['nullable', 'integer'],
            'editPhoneNumber' => ['required', 'string', 'max:20'],
            'editCnicNumber' => ['required', 'string', 'max:20'],
            'editAmount' => ['required', 'numeric', 'min:0'],
            'editFee' => ['required', 'numeric', 'min:0'],
            'editDiscount' => ['required', 'numeric', 'min:0'],
        ]);

        $verification = NadraVerification::findOrFail($this->editingId);

        UpdateNadraVerification::handle($verification, [
            'phone_number' => $this->editPhoneNumber,
            'cnic_number' => $this->editCnicNumber,
            'amount' => (float) $this->editAmount,
            'fee' => (float) $this->editFee,
            'discount' => (float) $this->editDiscount,
            'customer_id' => $this->editCustomerId !== '' ? (int) $this->editCustomerId : null,
        ], Auth::id());

        $this->toastSuccess('Verification updated.');
        $this->dispatch('close-modal', name: 'edit-verification-form');
        $this->reset(['editingId', 'editCustomerId', 'editPhoneNumber', 'editCnicNumber', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-verification-form');
        $this->reset(['editingId', 'editCustomerId', 'editPhoneNumber', 'editCnicNumber', 'editAmount', 'editFee', 'editDiscount']);
    }

    public function openCancel(int $verificationId): void
    {
        abort_unless(Auth::user()->hasAccessTo('nadra-verifications-edit-cancel'), 403);

        $this->cancelingId = $verificationId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-verification-form');
    }

    public function cancelVerification(): void
    {
        abort_unless(Auth::user()->hasAccessTo('nadra-verifications-edit-cancel'), 403);

        $verification = NadraVerification::findOrFail($this->cancelingId);
        CancelNadraVerification::handle($verification, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-verification-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $this->toastSuccess('Verification cancelled.');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-verification-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>
            NADRA Verification History
            <x-slot:actions>
                <x-ui.button href="{{ route('nadra-verifications.index') }}" wire:navigate size="sm">New Verification</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to) + (int) filled($paymentStatusFilter)">
        <x-ui.date-range />

        <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="w-full sm:w-44">
            <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                <option value="">All Statuses</option>
                @foreach ($paymentStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        @if ($from || $to || $paymentStatusFilter)
            <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                    Clear
                </x-ui.button>
        @endif
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Total Collected" value="Rs {{ number_format($totalCollected, 2) }}" />
        <x-ui.stat label="Total Owed by Customers" value="Rs {{ number_format($totalOwed, 2) }}" />
    </x-ui.stat-row>

    @if ($verifications->isEmpty())
        <x-ui.empty-state
            title="No NADRA verifications yet"
            description="Completed verifications will show up here."
        >
            <x-slot name="action">
                <x-ui.button href="{{ route('nadra-verifications.index') }}" wire:navigate>New Verification</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        <x-ui.table stack :headers="['Receipt', 'Phone', 'CNIC', 'Customer', 'Total', 'Payment', '']">
            @foreach ($verifications as $verification)
                <x-ui.table-row wire:key="nadra-verification-{{ $verification->id }}" @class(['opacity-50' => $verification->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink">{{ $verification->receiptNumber() }}<span class="ui-sub">{{ $verification->created_at->format('d M Y, h:i A') }}</span></x-ui.table-cell>
                    <x-ui.table-cell label="Phone">{{ $verification->phone_number }}</x-ui.table-cell>
                    <x-ui.table-cell label="CNIC">{{ $verification->cnic_number }}</x-ui.table-cell>
                    <x-ui.table-cell label="Customer">{{ $verification->customer?->name ?? 'Walk-in' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Total" class="font-semibold text-ink">Rs {{ number_format($verification->total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($verification->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($verification->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($verification->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                        @if (! $verification->isCancelled() && $verification->amountOwed() > 0)
                            <span class="ui-sub font-semibold text-ink">Rs {{ number_format($verification->amountOwed(), 2) }} owed</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $verification->receiptNumber() }}">
                            @if (! $verification->isCancelled() && $verification->payment_status->value !== 'paid')
                                <x-ui.menu-item wire:click="openAddPayment({{ $verification->id }})">Add Payment</x-ui.menu-item>
                                <x-ui.menu-item wire:click="markAsPaid({{ $verification->id }})" confirm="Mark this verification as fully paid?" confirm-title="Mark as paid?" confirm-label="Mark as Paid">Mark as Paid</x-ui.menu-item>
                            @endif
                            <x-ui.menu-item wire:click="downloadReceipt({{ $verification->id }})">Download</x-ui.menu-item>
                            @if (! $verification->isCancelled() && auth()->user()->hasAccessTo('nadra-verifications-edit-cancel'))
                                <x-ui.menu-item wire:click="openEdit({{ $verification->id }})">Edit</x-ui.menu-item>
                                <x-ui.menu-divider />
                                <x-ui.menu-item danger wire:click="openCancel({{ $verification->id }})">Delete</x-ui.menu-item>
                            @endif
                        </x-ui.row-actions>
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

    <x-ui.modal name="edit-verification-form" max-width="lg">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Edit Verification</h2>

            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field label="Customer" name="editCustomerId" for="editCustomerId" help="Leave blank for a walk-in customer">
                    <x-ui.select wire:model="editCustomerId" id="editCustomerId">
                        <option value="">Walk-in (no customer)</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Phone Number" name="editPhoneNumber" for="editPhoneNumber">
                    <x-ui.input wire:model="editPhoneNumber" id="editPhoneNumber" type="tel" />
                </x-ui.field>

                <x-ui.field label="CNIC Number" name="editCnicNumber" for="editCnicNumber">
                    <x-ui.input wire:model="editCnicNumber" id="editCnicNumber" />
                </x-ui.field>

                <x-ui.field label="Amount" name="editAmount" for="editAmount">
                    <x-ui.input wire:model="editAmount" id="editAmount" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Service Fee" name="editFee" for="editFee">
                    <x-ui.input wire:model="editFee" id="editFee" type="number" min="0" step="0.01" />
                </x-ui.field>

                <x-ui.field label="Discount" name="editDiscount" for="editDiscount">
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

    <x-ui.modal name="cancel-verification-form" max-width="sm">
        <form wire:submit="cancelVerification" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Verification?</h2>
            <p class="mt-2 text-sm text-ink-muted">
                This verification will be marked <strong>Cancelled</strong>. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Verification
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelVerification" variant="danger">
                    Cancel Verification
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
