<?php

use App\Enums\SubscriptionInvoiceStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Shop;
use App\Models\SubscriptionInvoice;
use App\Services\SubscriptionBillingService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component
{
    use Toasts, WithPagination;

    public int $shopId;

    public ?int $editingId = null;
    public string $periodStart = '';
    public string $months = '1';
    public string $amount = '';
    public string $discount = '';
    public string $notes = '';
    public bool $markPaid = false;
    public string $paidAt = '';

    public ?int $cancellingId = null;
    public string $cancelReason = '';

    public function mount(int $shopId): void
    {
        $this->authorizeSuperAdmin();

        $this->shopId = $shopId;
        $this->resetFormDefaults();
    }

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    protected function shop(): Shop
    {
        return Shop::findOrFail($this->shopId);
    }

    protected function invoiceFor(int $id): SubscriptionInvoice
    {
        return SubscriptionInvoice::where('shop_id', $this->shopId)->findOrFail($id);
    }

    protected function resetFormDefaults(): void
    {
        $shop = $this->shop();

        $this->periodStart = $shop->subscription_start_date?->toDateString() ?? now()->toDateString();
        $this->months = $shop->plan_type?->value === 'yearly' ? '12' : '1';
        $this->paidAt = now()->toDateString();
    }

    protected function resetForm(): void
    {
        $this->reset(['editingId', 'amount', 'discount', 'notes', 'markPaid']);
        $this->resetFormDefaults();
        $this->resetErrorBag();
    }

    public function with(): array
    {
        $this->authorizeSuperAdmin();

        $totals = SubscriptionInvoice::where('shop_id', $this->shopId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total ELSE 0 END), 0) AS billed,
                COALESCE(SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END), 0) AS paid,
                COALESCE(SUM(CASE WHEN status = 'unpaid' THEN total ELSE 0 END), 0) AS outstanding
            ")
            ->first();

        return [
            'shop' => $this->shop(),
            'invoices' => SubscriptionInvoice::where('shop_id', $this->shopId)->latest('id')->paginate(10),
            'totalBilled' => (float) $totals->billed,
            'totalPaid' => (float) $totals->paid,
            'outstanding' => (float) $totals->outstanding,
        ];
    }

    public function openCreate(): void
    {
        $this->authorizeSuperAdmin();

        $this->resetForm();
        $this->dispatch('open-modal', name: 'billing-invoice-form');
    }

    public function openEdit(int $id): void
    {
        $this->authorizeSuperAdmin();

        $invoice = $this->invoiceFor($id);

        if ($invoice->isCancelled()) {
            $this->toastError('A cancelled invoice cannot be edited.');

            return;
        }

        $this->editingId = $invoice->id;
        $this->periodStart = $invoice->period_start->toDateString();
        $this->months = (string) $invoice->months;
        $this->amount = (string) $invoice->amount;
        $this->discount = (string) $invoice->discount;
        $this->notes = (string) $invoice->notes;
        $this->markPaid = false;

        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'billing-invoice-form');
    }

    public function save(SubscriptionBillingService $billing): void
    {
        $this->authorizeSuperAdmin();

        $this->validate([
            'periodStart' => ['required', 'date'],
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'discount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paidAt' => [$this->markPaid && $this->editingId === null ? 'required' : 'nullable', 'date', 'before_or_equal:today'],
        ], [
            'discount.lte' => 'The discount cannot be more than the amount.',
        ]);

        $data = [
            'period_start' => $this->periodStart,
            'months' => (int) $this->months,
            'amount' => $this->amount,
            'discount' => $this->discount !== '' ? $this->discount : 0,
            'notes' => $this->notes,
        ];

        if ($this->editingId) {
            $billing->update($this->invoiceFor($this->editingId), $data);
            $this->toastSuccess('Billing record updated.');
        } else {
            $billing->create(
                $this->shop(),
                $data,
                Auth::id(),
                $this->markPaid ? ($this->paidAt !== '' ? $this->paidAt : now()->toDateString()) : null,
            );
            $this->toastSuccess('Billing record added.');
        }

        $this->dispatch('close-modal', name: 'billing-invoice-form');
        $this->resetForm();
    }

    public function markPaid(int $id, SubscriptionBillingService $billing): void
    {
        $this->authorizeSuperAdmin();

        try {
            $billing->markPaid($this->invoiceFor($id));
            $this->toastSuccess('Marked as paid.');
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function markUnpaid(int $id, SubscriptionBillingService $billing): void
    {
        $this->authorizeSuperAdmin();

        try {
            $billing->markUnpaid($this->invoiceFor($id));
            $this->toastSuccess('Marked as unpaid.');
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
        }
    }

    public function openCancel(int $id): void
    {
        $this->authorizeSuperAdmin();

        $invoice = $this->invoiceFor($id);

        if ($invoice->isCancelled()) {
            $this->toastError('This invoice is already cancelled.');

            return;
        }

        $this->cancellingId = $invoice->id;
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'billing-cancel-form');
    }

    public function cancelInvoice(SubscriptionBillingService $billing): void
    {
        $this->authorizeSuperAdmin();

        $this->validate([
            'cancelReason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $billing->cancel($this->invoiceFor($this->cancellingId), $this->cancelReason);
            $this->toastSuccess('Billing record cancelled. Its number is kept on record.');
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());
        }

        $this->dispatch('close-modal', name: 'billing-cancel-form');
        $this->reset(['cancellingId', 'cancelReason']);
    }

    public function closeInvoiceForm(): void
    {
        $this->dispatch('close-modal', name: 'billing-invoice-form');
        $this->resetForm();
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'billing-cancel-form');
        $this->reset(['cancellingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-ui.card title="Billing" description="Subscription records for this shop — kept separate from the Subscription card above, which is not changed by anything recorded here.">
        <x-slot name="actions">
            <x-ui.button type="button" size="sm" wire:click="openCreate">
                Add Record
            </x-ui.button>
        </x-slot>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat label="Total Billed" value="Rs {{ number_format($totalBilled, 2) }}" sub="Excludes cancelled records" />
            <x-ui.stat label="Total Paid" value="Rs {{ number_format($totalPaid, 2) }}" />
            <x-ui.stat label="Outstanding" value="Rs {{ number_format($outstanding, 2) }}" sub="Unpaid records" />
        </div>

        <div class="mt-6">
            @if ($invoices->isEmpty())
                <x-ui.empty-state
                    title="No billing records yet"
                    description="Add a record to bill this shop for a period. Records are never deleted, so numbering stays intact."
                />
            @else
                <x-ui.table :headers="['Invoice', 'Period', 'Total', 'Status', '']">
                    @foreach ($invoices as $invoice)
                        <x-ui.table-row wire:key="billing-{{ $invoice->id }}">
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $invoice->invoice_number }}</x-ui.table-cell>
                            <x-ui.table-cell>
                                <div class="whitespace-nowrap">{{ $invoice->period_start->format('d M Y') }} – {{ $invoice->period_end->format('d M Y') }}</div>
                                <div class="text-xs text-slate-400">{{ $invoice->months }} {{ \Illuminate\Support\Str::plural('month', $invoice->months) }} · {{ $invoice->planLabel() }}</div>
                            </x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">
                                Rs {{ number_format($invoice->total, 2) }}
                                @if ((float) $invoice->discount > 0)
                                    <div class="text-xs font-normal text-slate-400">after Rs {{ number_format($invoice->discount, 2) }} discount</div>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell>
                                @if ($invoice->status === SubscriptionInvoiceStatus::Paid)
                                    <x-ui.badge variant="success">Paid</x-ui.badge>
                                    @if ($invoice->paid_at)
                                        <div class="mt-1 text-xs text-slate-400">{{ $invoice->paid_at->format('d M Y') }}</div>
                                    @endif
                                @elseif ($invoice->status === SubscriptionInvoiceStatus::Cancelled)
                                    <x-ui.badge variant="neutral">Cancelled</x-ui.badge>
                                @else
                                    <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                <div class="flex flex-wrap justify-end gap-2">
                                    @if (! $invoice->isCancelled())
                                        <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $invoice->id }})">Edit</x-ui.button>
                                        @if ($invoice->isPaid())
                                            <x-ui.button size="sm" variant="ghost" wire:click="markUnpaid({{ $invoice->id }})" wire:confirm="Mark this record as unpaid?">Mark Unpaid</x-ui.button>
                                        @else
                                            <x-ui.button size="sm" variant="ghost" wire:click="markPaid({{ $invoice->id }})" wire:confirm="Mark this record as paid today?">Mark Paid</x-ui.button>
                                        @endif
                                        <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $invoice->id }})" class="text-red-600 hover:bg-red-50">Cancel</x-ui.button>
                                    @endif
                                    <a href="{{ route('admin.shops.billing.invoices.pdf', ['shop' => $shop->id, 'invoice' => $invoice->id]) }}" target="_blank" rel="noopener">
                                        <x-ui.button size="sm" variant="secondary">Download Invoice</x-ui.button>
                                    </a>
                                </div>
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>

                <div class="mt-4">
                    {{ $invoices->links() }}
                </div>
            @endif
        </div>
    </x-ui.card>

    <x-ui.modal name="billing-invoice-form" max-width="md">
        <form wire:submit="save" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">{{ $editingId ? 'Edit Billing Record' : 'Add Billing Record' }}</h2>

            <div class="mt-5 space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-ui.field label="Period Start" name="periodStart" for="billingPeriodStart">
                        <x-ui.input wire:model="periodStart" id="billingPeriodStart" type="date" />
                    </x-ui.field>

                    <x-ui.field label="Months Covered" name="months" for="billingMonths">
                        <x-ui.input wire:model="months" id="billingMonths" type="number" min="1" max="120" step="1" />
                    </x-ui.field>

                    <x-ui.field label="Amount (Rs)" name="amount" for="billingAmount">
                        <x-ui.input wire:model="amount" id="billingAmount" type="number" min="0" step="0.01" />
                    </x-ui.field>

                    <x-ui.field label="Discount (Rs)" name="discount" for="billingDiscount" help="Optional">
                        <x-ui.input wire:model="discount" id="billingDiscount" type="number" min="0" step="0.01" />
                    </x-ui.field>
                </div>

                <x-ui.field label="Notes" name="notes" for="billingNotes" help="Optional — shown on the invoice">
                    <x-ui.input wire:model="notes" id="billingNotes" />
                </x-ui.field>

                @if (! $editingId)
                    <div class="space-y-3 rounded-lg border border-slate-200 p-4">
                        <x-ui.checkbox wire:model.live="markPaid" label="Mark as paid" />

                        @if ($markPaid)
                            <x-ui.field label="Paid On" name="paidAt" for="billingPaidAt">
                                <x-ui.input wire:model="paidAt" id="billingPaidAt" type="date" />
                            </x-ui.field>
                        @endif
                    </div>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeInvoiceForm">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">
                    {{ $editingId ? 'Save Changes' : 'Add Record' }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="billing-cancel-form" max-width="sm">
        <form wire:submit="cancelInvoice" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Billing Record?</h2>
            <p class="mt-2 text-sm text-slate-600">
                It will be marked <strong>Cancelled</strong> and removed from the totals. Its invoice number stays on record and is never reused. Nothing is deleted.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="billingCancelReason">
                    <x-ui.input wire:model="cancelReason" id="billingCancelReason" autofocus />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">Keep Record</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelInvoice" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Record
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
