<?php

use App\Actions\CancelSale;
use App\Actions\SaleReturns;
use App\Models\SaleReturn;
use App\Livewire\Concerns\Toasts;
use App\Models\Sale;
use App\Services\InvoicePdfService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Sale Receipt')] class extends Component
{
    use Toasts, \Livewire\WithFileUploads;

    /** The prescription register details being filled in or corrected. */
    public array $prescription = [];
    public $prescriptionPhoto = null;

    public Sale $sale;

    public string $cancelReason = '';

    public ?int $cancellingReturnId = null;
    public string $returnCancelReason = '';

    // True only when arriving straight from completing this sale on the register
    // (a one-time session flash), so the page opens as a confirmation.
    public bool $justCompleted = false;

    public function mount(Sale $sale): void
    {
        $this->sale = $sale->load(['items', 'customer', 'payments.shopAccount', 'installments']);
        $this->justCompleted = session('sale-completed') === $sale->id;
    }

    public function downloadInvoice(InvoicePdfService $pdf): StreamedResponse
    {
        return $pdf->download($this->sale);
    }

    public function downloadCreditNote(int $returnId, InvoicePdfService $pdf): StreamedResponse
    {
        $return = SaleReturn::withoutGlobalScopes()->where('shop_id', Auth::user()->shop_id)->where('sale_id', $this->sale->id)->findOrFail($returnId);

        return $pdf->downloadCreditNote($return);
    }

    public function openCancelReturn(int $returnId): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-returns'), 403);

        $this->cancellingReturnId = $returnId;
        $this->returnCancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cancel-return-form');
    }

    public function openPrescription(): void
    {
        $entry = $this->sale->prescriptions()->firstOrFail();
        $this->resetErrorBag();
        $this->prescriptionPhoto = null;
        $this->prescription = [
            'patient_name' => (string) $entry->patient_name,
            'prescriber_name' => (string) $entry->prescriber_name,
            'prescriber_reg_no' => (string) $entry->prescriber_reg_no,
            'prescribed_on' => (string) $entry->prescribed_on?->toDateString(),
            'emergency_reason' => (string) $entry->emergency_reason,
        ];
        $this->dispatch('open-modal', name: 'prescription-form');
    }

    public function savePrescription(): void
    {
        $this->resetErrorBag();

        try {
            \App\Actions\Prescriptions::updateDetails($this->sale, $this->prescription + ['photo' => $this->prescriptionPhoto]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($messages, $key) => [$key === 'prescription.photo' ? 'prescriptionPhoto' : $key => $messages])->all());
        }

        $this->prescriptionPhoto = null;
        $this->dispatch('close-modal', name: 'prescription-form');
        $this->toastSuccess('Prescription register updated.');
    }

    public function cancelReturn(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-returns'), 403);

        $return = SaleReturn::withoutGlobalScopes()->where('shop_id', Auth::user()->shop_id)->where('sale_id', $this->sale->id)->findOrFail($this->cancellingReturnId);

        try {
            SaleReturns::cancel($return, Auth::id(), $this->returnCancelReason);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('returnCancelReason', collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('close-modal', name: 'cancel-return-form');
        $this->cancellingReturnId = null;
        $this->sale = $this->sale->fresh(['items', 'customer', 'payments.shopAccount', 'installments']);
        $this->toastSuccess($return->number().' cancelled. Its goods have left stock again.');
    }

    public function openCancel(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-sale-form');
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-sale-form');
        $this->cancelReason = '';
    }

    public function cancelSale(): void
    {
        abort_unless(Auth::user()->hasAccessTo('sales-edit-cancel'), 403);

        $result = CancelSale::handle($this->sale, Auth::id(), $this->cancelReason);

        $this->sale = $this->sale->fresh(['items', 'customer', 'payments.shopAccount', 'installments']);
        $this->dispatch('close-modal', name: 'cancel-sale-form');

        $message = 'Sale cancelled and stock restored.';
        if (! empty($result['skipped'])) {
            $message .= ' Could not restock (product deleted): '.implode(', ', $result['skipped']).'.';
        }

        $this->toastSuccess($message);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('sales.history') }}" back-label="Sales History">
            {{ $sale->invoiceNumber() }}
            <x-slot:actions>
                <x-ui.button href="{{ route('sales.index') }}" wire:navigate variant="accent" size="sm" @class(['hidden' => $justCompleted && ! $sale->isCancelled()])>New Sale</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <div class="mx-auto max-w-2xl">
        @if (session('status'))
            <x-ui.alert variant="success" class="mb-4" data-status>{{ session('status') }}</x-ui.alert>
        @endif

        @php($saleReturns = $sale->isCancelled() ? collect() : SaleReturn::withoutGlobalScopes()->where('sale_id', $sale->id)->with('items')->orderBy('id')->get())
        @php($activeReturns = $saleReturns->filter(fn ($return) => ! $return->isCancelled()))

        @if ($justCompleted && ! $sale->isCancelled())
            @php($due = $sale->amountDue())
            <div class="mb-5 rounded-2xl border border-success/20 bg-success-soft px-6 py-7 text-center" data-sale-complete>
                <span class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-success text-surface">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                </span>
                <h2 class="mt-3 text-lg font-extrabold tracking-tight text-ink">Sale complete</h2>
                <p class="mt-1 text-4xl font-extrabold tracking-tight tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</p>
                <p class="mt-1 text-sm text-ink-muted">
                    @if ($due > 0)
                        Rs {{ number_format($due, 2) }} due{{ $sale->customer ? ' from '.$sale->customer->name : '' }}
                    @else
                        Paid in full
                    @endif
                    · {{ $sale->items->count() }} {{ \Illuminate\Support\Str::plural('line', $sale->items->count()) }}
                </p>
                <div class="mt-5 flex flex-col items-stretch justify-center gap-2 sm:flex-row sm:items-center">
                    <x-ui.button href="{{ route('sales.index') }}" wire:navigate variant="accent" size="lg" x-init="$el.focus()" data-next-sale class="justify-center px-8 py-3 text-lg">New Sale</x-ui.button>
                    <x-ui.button wire:click="downloadInvoice" variant="secondary" size="lg" class="justify-center">Download invoice</x-ui.button>
                </div>
                <p class="mt-3 hidden text-xs text-ink-faint sm:block">Press Enter to start the next sale.</p>
            </div>
        @endif

        @if ($sale->isCancelled())
            <x-ui.alert variant="danger" class="mb-4">
                <p class="font-semibold">This sale was cancelled{{ $sale->cancelled_at ? ' on '.$sale->cancelled_at->format('d M Y, h:i A') : '' }}.</p>
                @if ($sale->cancel_reason)
                    <p class="mt-1">Reason: {{ $sale->cancel_reason }}</p>
                @endif
                <p class="mt-1">Stock was restored and this sale no longer counts toward any total.</p>
            </x-ui.alert>
        @endif

        <x-ui.card>
            <x-slot name="actions">
                <div class="flex flex-wrap justify-end gap-2">
                    @if (! $sale->isCancelled() && auth()->user()->hasAccessTo('sales-returns'))
                        <x-ui.button href="{{ route('sales.return', $sale) }}" wire:navigate variant="secondary" size="sm" data-return-items>Return Items</x-ui.button>
                        <x-ui.button href="{{ route('sales.index', ['exchange' => $sale->id]) }}" wire:navigate variant="secondary" size="sm" data-exchange-items>Exchange</x-ui.button>
                    @endif
                    @if (! $sale->isCancelled() && $activeReturns->isEmpty() && auth()->user()->hasAccessTo('sales-edit-cancel'))
                        <x-ui.button href="{{ route('sales.edit', $sale) }}" wire:navigate variant="secondary" size="sm">Edit</x-ui.button>
                        <x-ui.button wire:click="openCancel" variant="danger-outline" size="sm">
                            Delete
                        </x-ui.button>
                    @endif
                    <x-ui.button wire:click="downloadInvoice" variant="secondary" size="sm">
                        Download PDF
                    </x-ui.button>
                </div>
            </x-slot>

            <div class="flex items-center justify-between border-b border-line pb-4">
                <div>
                    <p class="text-sm text-ink-faint">Date</p>
                    <p class="font-semibold text-ink">{{ $sale->created_at->format('d M Y, h:i A') }}</p>
                </div>
                @if ($sale->user)
                    <div class="text-right">
                        <p class="text-sm text-ink-faint">Served by</p>
                        <p class="font-semibold text-ink">{{ $sale->user->name }}</p>
                    </div>
                @endif
            </div>

            @if ($sale->customer)
                <div class="flex items-center justify-between border-b border-line py-4">
                    <p class="text-sm text-ink-faint">Customer</p>
                    <a href="{{ route('customers.show', $sale->customer) }}" wire:navigate class="font-semibold text-link hover:underline">
                        {{ $sale->customer->name }}
                    </a>
                </div>
            @endif

            <div class="mt-4 divide-y divide-line">
                @foreach ($sale->items as $item)
                    <div class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-ink">{{ $item->product_name }}</p>
                            <p class="text-xs text-ink-faint">
                                {{ $item->unit->amount($item->quantity) }}{{ ($packLabel = $item->packLabel()) ? ' ('.$packLabel.')' : '' }} × Rs {{ number_format($item->unit_price, 2) }}
                                @if ($item->discount_amount > 0)
                                    · Rs {{ number_format($item->discount_amount, 2) }} off
                                @endif
                            </p>
                            @if ($promotionNote = $item->promotionNote())
                                <p class="text-xs font-semibold text-success" data-item-promotion>{{ $promotionNote }}</p>
                            @endif
                            @if ($item->serial_number || $item->hasWarranty())
                                <p class="text-xs text-ink-faint" data-item-warranty>
                                    @if ($item->serial_number)
                                        IMEI {{ $item->serial_number }}
                                    @endif
                                    @if ($item->hasWarranty())
                                        {{ $item->serial_number ? '·' : '' }} {{ $item->warranty_months }}-month {{ strtolower($item->warranty_type?->label() ?? 'warranty') }}, until {{ $item->warrantyEndsOn($sale->created_at)->format('d M Y') }}
                                    @endif
                                </p>
                            @endif
                        </div>
                        <span class="whitespace-nowrap text-sm font-bold tabular-nums text-ink">Rs {{ number_format($item->line_total, 2) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 space-y-2 border-t border-line pt-4">
                <div class="flex items-center justify-between text-sm">
                    <span class="text-ink-faint">Subtotal</span>
                    <span class="font-medium tabular-nums text-ink">Rs {{ number_format($sale->subtotal, 2) }}</span>
                </div>
                @if ((float) $sale->discount_amount > 0)
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-ink-faint">Discount</span>
                        <span class="font-medium tabular-nums text-ink">Rs {{ number_format($sale->discount_amount, 2) }}</span>
                    </div>
                @endif
                <div class="flex items-center justify-between border-t border-line pt-2">
                    <span class="font-semibold text-ink-faint">Total</span>
                    <span class="text-display-sm font-extrabold tabular-nums text-ink">Rs {{ number_format($sale->total, 2) }}</span>
                </div>
            </div>

            <div class="mt-4 flex items-center justify-between rounded-xl border border-line bg-surface-sunken/50 px-4 py-4">
                <div class="flex gap-6">
                    <div>
                        <p class="text-sm text-ink-faint">Paid</p>
                        <p class="font-bold tabular-nums text-ink">Rs {{ number_format($sale->amountPaid(), 2) }}</p>
                    </div>
                    <div>
                        <p class="text-sm text-ink-faint">Due</p>
                        <p class="font-bold tabular-nums text-ink">Rs {{ number_format($sale->amountDue(), 2) }}</p>
                    </div>
                </div>
                @if ($sale->isCancelled())
                    <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                @elseif ($sale->paymentStatus() === 'paid')
                    <x-ui.badge variant="success">Paid</x-ui.badge>
                @elseif ($sale->paymentStatus() === 'partial')
                    <x-ui.badge variant="warning">Partial</x-ui.badge>
                @else
                    <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                @endif
            </div>

            @unless ($sale->isCancelled())
                <x-shipments.order-courier :sale-id="$sale->id" class="mt-3 rounded-xl border border-line px-4 py-3" />
            @endunless

            @php($recordedPayments = $sale->payments->filter(fn ($payment) => (float) $payment->amount != 0.0)->sortBy('id'))
            @if ($recordedPayments->isNotEmpty())
                <ul class="mt-3 space-y-1 text-sm">
                    @foreach ($recordedPayments as $payment)
                        <li class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-ink-faint">{{ $payment->payment_date->format('d M Y') }} · Rs {{ number_format($payment->amount, 2) }}</span>
                            <span class="text-ink-muted">Paid by: <span class="font-semibold text-ink">{{ $payment->methodLabel() }}</span></span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @php($rxEntries = $sale->prescriptions)
            @if ($rxEntries->isNotEmpty())
                @php($rx = $rxEntries->first())
                <div class="mt-5 border-t border-line pt-4" data-sale-prescriptions>
                    <div class="flex items-center justify-between gap-3">
                        <h3 class="text-sm font-semibold text-ink">Prescription register</h3>
                        <button type="button" wire:click="openPrescription" class="text-xs font-semibold text-link hover:underline">{{ $rx->detailsMissing() ? 'Add details' : 'Edit details' }}</button>
                    </div>
                    <ul class="mt-2 space-y-1 text-sm text-ink">
                        @foreach ($rxEntries as $entry)
                            <li wire:key="rx-{{ $entry->id }}">No. {{ $entry->serial_number }} · {{ $entry->product_name }} × {{ \App\Support\Quantity::normalize($entry->quantity) }} <span class="text-xs text-ink-faint">({{ \App\Models\SalePrescription::controlLabel($entry->control) }})</span></li>
                        @endforeach
                    </ul>
                    <p class="mt-2 text-xs text-ink-faint">
                        {{ $rx->patient_name ? 'Patient: '.$rx->patient_name.' · ' : '' }}{{ $rx->detailsMissing() ? 'Prescriber not recorded yet (sale not made at the register)' : ($rx->is_emergency ? 'Emergency sale: '.$rx->emergency_reason : 'Prescriber: '.$rx->prescriber_name.($rx->prescriber_reg_no ? ' ('.$rx->prescriber_reg_no.')' : '')) }}
                    </p>
                    @if ($rx->photo_path)
                        <a href="{{ route('prescriptions.photo', $rx) }}" target="_blank" rel="noopener" class="mt-1 inline-block text-xs font-semibold text-link hover:underline" data-prescription-photo>View prescription photo</a>
                    @endif
                </div>
            @endif

            @if ($saleReturns->isNotEmpty())
                <div class="mt-5 border-t border-line pt-4" data-sale-returns>
                    <h3 class="text-sm font-semibold text-ink">Returns</h3>
                    @if ($activeReturns->isNotEmpty() && auth()->user()->hasAccessTo('sales-edit-cancel'))
                        <p class="mt-1 text-xs text-ink-faint">A sale with a return can't be edited or deleted. Cancel its returns first.</p>
                    @endif
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($saleReturns as $saleReturn)
                            <li wire:key="sale-return-{{ $saleReturn->id }}" @class(['py-2.5', 'opacity-60' => $saleReturn->isCancelled()])>
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-semibold text-ink">
                                        {{ $saleReturn->number() }} · {{ $saleReturn->return_date->format('d M Y') }}
                                        @if ($saleReturn->isCancelled())
                                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                                        @endif
                                    </span>
                                    <span class="font-bold tabular-nums text-ink">Rs {{ number_format($saleReturn->value_total, 2) }}</span>
                                </div>
                                <p class="text-xs text-ink-faint">
                                    {{ $saleReturn->items->map(fn ($item) => $item->product_name.' × '.$item->unit->amount($item->quantity).($item->disposition === 'damaged' ? ' (damaged)' : ''))->implode(', ') }}
                                </p>
                                <p class="text-xs text-ink-muted">
                                    @if ((float) $saleReturn->due_reduction > 0)
                                        Rs {{ number_format($saleReturn->due_reduction, 2) }} off what was owed.
                                    @endif
                                    @if ((float) $saleReturn->refund_cash > 0)
                                        Rs {{ number_format($saleReturn->refund_cash, 2) }} handed back from {{ $saleReturn->refundSourceLabel() }}.
                                    @endif
                                    @if ((float) $saleReturn->refund_credit > 0)
                                        Rs {{ number_format($saleReturn->refund_credit, 2) }} given as store credit to {{ $saleReturn->customer?->name ?? 'the customer' }}.
                                    @endif
                                    @if ($saleReturn->exchange_sale_id)
                                        Exchanged:
                                        @if ((float) $saleReturn->refund_exchange > 0)
                                            Rs {{ number_format($saleReturn->refund_exchange, 2) }} paid toward
                                        @endif
                                        <a href="{{ route('sales.show', $saleReturn->exchange_sale_id) }}" wire:navigate class="font-semibold text-link hover:underline">INV-{{ str_pad((string) $saleReturn->exchange_sale_id, 6, '0', STR_PAD_LEFT) }}</a>.
                                    @endif
                                    @if ($saleReturn->reason)
                                        Reason: {{ $saleReturn->reason }}.
                                    @endif
                                </p>
                                <div class="mt-1 flex gap-3 text-xs font-semibold">
                                    <button type="button" wire:click="downloadCreditNote({{ $saleReturn->id }})" class="text-link hover:underline">Credit note PDF</button>
                                    @if (! $saleReturn->isCancelled() && auth()->user()->hasAccessTo('sales-returns'))
                                        <button type="button" wire:click="openCancelReturn({{ $saleReturn->id }})" class="text-danger hover:underline">Cancel return</button>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @php($schedule = $sale->isCancelled() ? null : \App\Support\InstallmentSchedule::for($sale))
            @if ($schedule)
                <div class="mt-5 border-t border-line pt-4" data-installment-schedule>
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="text-sm font-semibold text-ink">Instalment Plan</h3>
                        <p class="text-xs text-ink-faint">{{ $schedule->summary() }}</p>
                    </div>
                    <ul class="mt-2 divide-y divide-line text-sm">
                        @foreach ($schedule->rows() as $row)
                            <li class="flex items-center justify-between gap-3 py-2">
                                <span class="text-ink-muted">{{ $row['sequence'] }} of {{ $schedule->count() }} · {{ $row['due_date']->format('d M Y') }}</span>
                                <span class="flex items-center gap-3">
                                    <span class="font-semibold tabular-nums text-ink">
                                        Rs {{ number_format($row['amount'], 2) }}
                                        @if ($row['paid'] > 0 && $row['left'] > 0)
                                            <span class="block text-right text-xs font-normal text-ink-faint">Rs {{ number_format($row['left'], 2) }} left</span>
                                        @endif
                                    </span>
                                    <x-ui.badge :variant="\App\Support\InstallmentSchedule::statusVariant($row['status'])">{{ \App\Support\InstallmentSchedule::statusLabel($row['status']) }}</x-ui.badge>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                    @if ($sale->customer && auth()->user()->hasAccessTo('udhaar'))
                        <p class="mt-2 text-xs text-ink-faint">Take a payment from <a href="{{ route('udhaar.show', $sale->customer) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $sale->customer->name }}’s udhaar page</a>; it counts against the earliest instalment first.</p>
                    @endif
                </div>
            @endif
        </x-ui.card>

        <x-ui.modal name="prescription-form" max-width="lg">
            <form wire:submit="savePrescription" class="p-6">
                <h2 class="text-lg font-semibold text-ink">Prescription register</h2>
                <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Patient name" name="prescription.patient_name" for="rxEditPatient">
                        <x-ui.input wire:model="prescription.patient_name" id="rxEditPatient" maxlength="120" />
                    </x-ui.field>
                    <x-ui.field label="Prescriber (doctor)" name="prescription.prescriber_name" for="rxEditPrescriber" help="Leave blank for an emergency sale">
                        <x-ui.input wire:model="prescription.prescriber_name" id="rxEditPrescriber" maxlength="120" />
                    </x-ui.field>
                    <x-ui.field label="PMDC / registration no." name="prescription.prescriber_reg_no" for="rxEditRegNo">
                        <x-ui.input wire:model="prescription.prescriber_reg_no" id="rxEditRegNo" maxlength="40" />
                    </x-ui.field>
                    <x-ui.field label="Prescription date" name="prescription.prescribed_on" for="rxEditDate">
                        <x-ui.input wire:model="prescription.prescribed_on" id="rxEditDate" type="date" />
                    </x-ui.field>
                    <x-ui.field label="Emergency reason" name="prescription.emergency_reason" for="rxEditReason" class="sm:col-span-2" help="Needed when no prescriber is entered">
                        <x-ui.input wire:model="prescription.emergency_reason" id="rxEditReason" maxlength="255" />
                    </x-ui.field>
                    <x-ui.field label="Prescription photo" name="prescriptionPhoto" for="rxEditPhoto" class="sm:col-span-2" help="A new photo or PDF replaces the old one">
                        <input type="file" wire:model="prescriptionPhoto" id="rxEditPhoto" accept="image/*,application/pdf" class="block w-full text-sm text-ink-faint file:mr-4 file:rounded-full file:border-0 file:bg-primary-soft file:px-4 file:py-2 file:text-sm file:font-semibold file:text-on-primary-soft hover:file:bg-primary-soft/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-link">
                    </x-ui.field>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'prescription-form' })">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="savePrescription,prescriptionPhoto">Save</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="cancel-return-form" max-width="sm">
            <form wire:submit="cancelReturn" class="p-6">
                <h2 class="text-lg font-bold text-ink">Cancel This Return?</h2>
                <p class="mt-2 text-sm text-ink-muted">
                    The goods taken back leave stock again, and the refund and anything taken off what was owed stop counting. Money already handed back isn't recorded as paid in again. It can't be cancelled once those goods have been sold again.
                </p>

                <div class="mt-5">
                    <x-ui.field label="Reason" name="returnCancelReason" for="returnCancelReason" help="Optional">
                        <x-ui.input wire:model="returnCancelReason" id="returnCancelReason" />
                    </x-ui.field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cancel-return-form' })">Keep Return</x-ui.button>
                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelReturn">Cancel Return</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="cancel-sale-form" max-width="sm">
            <form wire:submit="cancelSale" class="p-6">
                <h2 class="text-lg font-bold text-ink">Cancel This Sale?</h2>
                <p class="mt-2 text-sm text-ink-muted">
                    This sale will be marked <strong>Cancelled</strong> and its stock will be restored. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
                </p>

                <div class="mt-5">
                    <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                        <x-ui.input wire:model="cancelReason" id="cancelReason" />
                    </x-ui.field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                        Keep Sale
                    </x-ui.button>

                    <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="cancelSale">
                        Cancel Sale
                    </x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    </div>
</div>
