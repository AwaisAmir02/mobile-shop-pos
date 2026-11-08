<?php

use App\Actions\VendorInvoices;
use App\Livewire\Concerns\Toasts;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\VendorInvoice;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Vendor Invoice')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use Toasts;

    public int $invoiceId;

    public string $reviewNote = '';
    public string $reviewedFingerprint = '';
    public string $cancelReason = '';
    public string $payAmount = '';

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('vendor-invoices') || Auth::user()->hasAccessTo('vendor-invoices-approve'), 403);
    }

    public function mount(int $invoice): void
    {
        $this->invoiceId = $invoice;
        $this->invoice();
    }

    protected function invoice(): VendorInvoice
    {
        return VendorInvoice::withoutGlobalScope(ExcludesCancelledScope::class)
            ->touchingCurrentBranch()
            ->with(['vendor', 'user', 'reviewer'])
            ->findOrFail($this->invoiceId);
    }

    protected function canApprove(): bool
    {
        return Auth::user()->hasAccessTo('vendor-invoices-approve');
    }

    public function with(): array
    {
        $invoice = $this->invoice();
        $match = VendorInvoices::evaluate($invoice);
        $withinBranch = $invoice->isWithinUsersBranch();
        $canReview = $this->canApprove() && $withinBranch;

        return [
            'invoice' => $invoice,
            'match' => $match,
            'changing' => collect($match['lines'])->filter(fn (array $entry) => $entry['changes']),
            'withinBranch' => $withinBranch,
            'canReview' => $canReview,
            'canApproveNow' => $canReview && $invoice->isFlagged() && Auth::user()->shop?->purchase_orders_enabled,
            'canPay' => $canReview && $invoice->isApproved() && $invoice->amountOwed() > 0,
            // Mistakes in entry can be undone by whoever records; undoing an approval needs approval rights.
            'canCancel' => ! $invoice->isCancelled() && $withinBranch && ($invoice->isApproved() ? $this->canApprove() : true),
        ];
    }

    public function openApprove(): void
    {
        abort_unless($this->canApprove(), 403);

        $this->reviewNote = '';
        $this->reviewedFingerprint = VendorInvoices::evaluate($this->invoice())['fingerprint'];
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'invoice-approve');
    }

    public function approve(): void
    {
        abort_unless($this->canApprove(), 403);

        $this->resetErrorBag();
        VendorInvoices::approve($this->invoice(), $this->reviewNote, Auth::id(), $this->reviewedFingerprint);

        $this->dispatch('close-modal', name: 'invoice-approve');
        $this->toastSuccess('Invoice approved.');
    }

    public function closeApprove(): void
    {
        $this->dispatch('close-modal', name: 'invoice-approve');
    }

    public function openDispute(): void
    {
        abort_unless($this->canApprove(), 403);

        $this->reviewNote = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'invoice-dispute');
    }

    public function dispute(): void
    {
        abort_unless($this->canApprove(), 403);

        $this->resetErrorBag();
        VendorInvoices::dispute($this->invoice(), $this->reviewNote, Auth::id());

        $this->dispatch('close-modal', name: 'invoice-dispute');
        $this->toastSuccess('Invoice disputed. What you owe stays at the received figures.');
    }

    public function closeDispute(): void
    {
        $this->dispatch('close-modal', name: 'invoice-dispute');
    }

    public function openCancel(): void
    {
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'invoice-cancel');
    }

    public function cancel(): void
    {
        $invoice = $this->invoice();
        abort_unless(! $invoice->isApproved() || $this->canApprove(), 403);

        $this->resetErrorBag();
        VendorInvoices::cancel($invoice, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'invoice-cancel');
        $this->toastSuccess('Invoice cancelled. Its received items can go on a corrected invoice.');
    }

    public function closeCancel(): void
    {
        $this->dispatch('close-modal', name: 'invoice-cancel');
    }

    public function openPay(): void
    {
        abort_unless($this->canApprove(), 403);

        $invoice = $this->invoice()->load('lines.stockIn');
        $this->payAmount = (string) $invoice->amountOwed();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'invoice-pay');
    }

    public function pay(): void
    {
        abort_unless($this->canApprove(), 403);

        $this->validate(['payAmount' => ['required', 'numeric', 'min:0.01']], [], ['payAmount' => 'amount']);

        VendorInvoices::pay($this->invoice(), (float) $this->payAmount, Auth::id(), ...$this->paidFromParts());
        $this->paidFrom = '';

        $this->dispatch('close-modal', name: 'invoice-pay');
        $this->toastSuccess('Payment recorded.');
    }

    public function closePay(): void
    {
        $this->dispatch('close-modal', name: 'invoice-pay');
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('purchase-orders.invoices') }}" back-label="Vendor Invoices">{{ $invoice->number() }}</x-ui.page-header>
    </x-slot>

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-line bg-surface-sunken px-4 py-3 text-sm text-ink-muted">{{ session('status') }}</div>
    @endif

    <x-ui.error :messages="$errors->get('invoice')" class="mb-4" />

    @if ($invoice->isFlagged() && $match['reasons'] !== [])
        <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
            <p class="font-semibold">This invoice needs review</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach ($match['reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if (! $withinBranch && ! $invoice->isCancelled())
        <div class="mb-4 rounded-xl border border-line bg-surface-sunken px-4 py-3 text-sm text-ink-muted">
            This invoice covers goods received at another branch, so it needs someone with access to the whole shop.
        </div>
    @endif

    <div class="mb-4 flex flex-wrap justify-end gap-2">
        @if ($canApproveNow)
            <x-ui.button size="sm" wire:click="openApprove">Approve</x-ui.button>
            <x-ui.button size="sm" variant="secondary" wire:click="openDispute">Dispute</x-ui.button>
        @endif
        @if ($canPay)
            <x-ui.button size="sm" wire:click="openPay">Pay</x-ui.button>
        @endif
        @if ($canCancel)
            <x-ui.button size="sm" variant="danger-ghost" wire:click="openCancel">Cancel Invoice</x-ui.button>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-ui.card title="Invoice">
            <dl class="space-y-3 text-sm">
                <div class="flex items-center justify-between"><dt class="text-ink-faint">Status</dt><dd><x-ui.badge :variant="$invoice->status->badge()">{{ $invoice->status->label() }}</x-ui.badge></dd></div>
                <div><dt class="text-ink-faint">Vendor</dt><dd class="font-medium text-ink">{{ $invoice->vendor?->name ?? '—' }}</dd></div>
                <div><dt class="text-ink-faint">Vendor's Invoice No.</dt><dd class="font-medium text-ink">{{ $invoice->invoice_number }}</dd></div>
                <div><dt class="text-ink-faint">Invoice Date</dt><dd class="font-medium text-ink">{{ $invoice->invoice_date->format('d M Y') }}</dd></div>
                <div><dt class="text-ink-faint">Billed Total</dt><dd class="font-medium text-ink">Rs {{ number_format($invoice->billedTotal(), 2) }}</dd></div>
                @if ($invoice->isApproved())
                    <div><dt class="text-ink-faint">Paid</dt><dd class="font-medium text-ink">Rs {{ number_format($invoice->amountPaid(), 2) }}</dd></div>
                    <div><dt class="text-ink-faint">Owed</dt><dd class="font-semibold text-ink">Rs {{ number_format($invoice->amountOwed(), 2) }}</dd></div>
                @endif
                @if ($invoice->auto_passed)
                    <div><dt class="text-ink-faint">Approval</dt><dd class="font-medium text-ink">Matched within Rs 1</dd></div>
                @endif
                @if ($invoice->review_note)
                    <div><dt class="text-ink-faint">{{ $invoice->status->value === 'disputed' ? 'Disputed because' : 'Review note' }}</dt><dd class="font-medium text-ink">{{ $invoice->review_note }}</dd><dd class="text-xs text-ink-faint">{{ $invoice->reviewer?->name }}</dd></div>
                @endif
                @if ($invoice->note)
                    <div><dt class="text-ink-faint">Note</dt><dd class="font-medium text-ink">{{ $invoice->note }}</dd></div>
                @endif
                @if ($invoice->cancel_reason)
                    <div><dt class="text-ink-faint">Cancelled because</dt><dd class="font-medium text-ink">{{ $invoice->cancel_reason }}</dd></div>
                @endif
            </dl>
        </x-ui.card>

        <div class="space-y-6 lg:col-span-2">
            <x-ui.card title="Ordered, received, billed" :padding="false">
                <x-ui.table stack :headers="['Product', 'Ordered', 'Received', 'Billed', 'Difference']">
                    @foreach ($match['lines'] as $entry)
                        @php
                            $line = $entry['line'];
                            $row = $line->stockIn;
                            $poLine = $line->grnLine?->poLine;
                        @endphp
                        <x-ui.table-row wire:key="invoice-line-{{ $line->id }}">
                            <x-ui.table-cell primary class="font-medium text-ink">
                                {{ $row->product_name }}
                                @if ($line->amended)
                                    <p class="text-xs font-normal text-ink-faint">Cost corrected from Rs {{ number_format((float) $line->previous_total_cost, 2) }} to Rs {{ number_format((float) $row->total_cost, 2) }}</p>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Ordered">
                                {{ $poLine ? $poLine->unit->amount($poLine->quantity) : '—' }}
                                @if ($poLine?->expected_cost !== null)
                                    <p @class(['text-xs', 'font-semibold text-warning' => $entry['above_order'] > 0, 'text-ink-faint' => $entry['above_order'] == 0])>at Rs {{ number_format($poLine->expected_cost, 2) }}</p>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Received">
                                <span @class(['font-semibold text-warning' => $entry['quantity_differs']])>{{ $row->unit->amount($row->quantity) }}</span>
                                <p class="text-xs text-ink-faint">{{ $entry['received'] !== null ? 'Rs '.number_format($entry['received'], 2) : 'No cost' }}</p>
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Billed">
                                <span @class(['font-semibold text-warning' => $entry['quantity_differs']])>{{ $row->unit->amount($line->billed_quantity) }}</span>
                                <p class="text-xs text-ink-faint">Rs {{ number_format((float) $line->billed_amount, 2) }}</p>
                            </x-ui.table-cell>
                            <x-ui.table-cell label="Difference">
                                @if ($entry['difference'] > 0)
                                    <span class="font-semibold text-warning">Rs {{ number_format($entry['difference'], 2) }}</span>
                                @elseif ($entry['quantity_differs'])
                                    <span class="font-semibold text-warning">Quantity</span>
                                @else
                                    <span class="text-success">Matches</span>
                                @endif
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @if ($invoice->charges->isNotEmpty())
                <x-ui.card title="Delivery, round-off and discounts" :padding="false">
                    <x-ui.table stack :headers="['Charge', 'Amount']">
                        @foreach ($invoice->charges as $charge)
                            <x-ui.table-row wire:key="invoice-charge-{{ $charge->id }}">
                                <x-ui.table-cell primary>{{ $charge->label }}</x-ui.table-cell>
                                <x-ui.table-cell label="Amount">Rs {{ number_format((float) $charge->amount, 2) }}</x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>
    </div>

    <x-ui.modal name="invoice-approve" max-width="lg">
        <form wire:submit="approve" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Approve This Invoice?</h2>

            @if ($changing->isEmpty())
                <p class="mt-2 text-sm text-ink-muted">The billed figures match what was received, so what you owe won't change.</p>
            @else
                <p class="mt-2 text-sm text-ink-muted">This corrects these batches to what was billed:</p>
                <ul class="mt-3 space-y-3 text-sm">
                    @foreach ($changing as $entry)
                        <li class="rounded-lg bg-surface-sunken px-3 py-2" wire:key="approve-line-{{ $entry['line']->id }}">
                            <p class="font-medium text-ink">{{ $entry['line']->stockIn->product_name }}: Rs {{ number_format((float) ($entry['line']->stockIn->total_cost ?? 0), 2) }} → Rs {{ number_format($entry['new_total'], 2) }}</p>
                            <p class="mt-0.5 text-ink-muted">{{ $entry['line']->soldNote() }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif

            <x-ui.error :messages="$errors->get('invoice')" class="mt-3" />

            <div class="mt-5">
                <x-ui.field label="Reason" name="reviewNote" for="approveNote">
                    <x-ui.input wire:model="reviewNote" id="approveNote" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeApprove">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="approve">Approve Invoice</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="invoice-dispute" max-width="sm">
        <form wire:submit="dispute" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Dispute This Invoice?</h2>
            <p class="mt-2 text-sm text-ink-muted">Nothing changes: what you owe stays at the received figures. Its items and number stay on this invoice until you cancel it, which frees them for a corrected one.</p>
            <x-ui.error :messages="$errors->get('invoice')" class="mt-3" />
            <div class="mt-5">
                <x-ui.field label="What's wrong?" name="reviewNote" for="disputeNote">
                    <x-ui.input wire:model="reviewNote" id="disputeNote" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeDispute">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="dispute">Dispute Invoice</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="invoice-cancel" max-width="sm">
        <form wire:submit="cancel" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Invoice?</h2>
            <p class="mt-2 text-sm text-ink-muted">Its items and number become free for a corrected invoice.@if ($invoice->isApproved()) Any cost it corrected goes back to the received figure. Sales made since keep the cost they had. It's refused if more has been paid than that figure.@endif</p>
            <x-ui.error :messages="$errors->get('invoice')" class="mt-3" />
            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="invoiceCancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="invoiceCancelReason" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep It</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancel" variant="danger">Cancel Invoice</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="invoice-pay" max-width="sm">
        <form wire:submit="pay" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Pay This Invoice</h2>
            <p class="mt-2 text-sm text-ink-muted">Rs {{ number_format($invoice->amountOwed(), 2) }} is owed. The payment is shared across its batches and shows on Stock In History too.</p>
            <div class="mt-5">
                <x-ui.field label="Amount Paid Now" name="payAmount" for="payAmount">
                    <x-ui.input wire:model="payAmount" id="payAmount" type="number" min="0.01" step="0.01" />
                </x-ui.field>
                <div class="mt-4"><x-ledger.paid-from :value="$paidFrom" /></div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePay">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="pay">Save Payment</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
