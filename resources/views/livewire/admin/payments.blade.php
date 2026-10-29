<?php

use App\Enums\SubscriptionPaymentStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\SubscriptionPayment;
use App\Services\Billing\SubscriptionPaymentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Payments')] class extends Component
{
    use Toasts, WithPagination;

    #[Url]
    public string $status = 'pending';

    public ?int $rejectingId = null;
    public string $rejectReason = '';

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function confirm(int $id, SubscriptionPaymentService $payments): void
    {
        $this->authorizeSuperAdmin();

        try {
            $payment = $payments->confirm(SubscriptionPayment::findOrFail($id), Auth::id());
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->toastSuccess($payment->shopAddon ? 'Payment confirmed — '.$payment->shopAddon->name.' is now active for '.$payment->shop->name.'.' : 'Payment confirmed — '.$payment->shop->name.' is now active.');
    }

    public function openReject(int $id): void
    {
        $this->authorizeSuperAdmin();
        $this->resetValidation();
        $this->rejectingId = $id;
        $this->rejectReason = '';
        $this->dispatch('open-modal', name: 'reject-payment');
    }

    public function reject(SubscriptionPaymentService $payments): void
    {
        $this->authorizeSuperAdmin();

        $this->validate(['rejectReason' => ['required', 'string', 'min:5', 'max:500']], [], ['rejectReason' => 'reason']);

        try {
            $payments->reject(SubscriptionPayment::findOrFail($this->rejectingId), Auth::id(), $this->rejectReason);
        } catch (\RuntimeException $e) {
            $this->toastError($e->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'reject-payment');
        $this->reset(['rejectingId', 'rejectReason']);
        $this->toastSuccess('Payment rejected. The shop owner will see your reason.');
    }

    public function with(): array
    {
        $this->authorizeSuperAdmin();

        return [
            'payments' => SubscriptionPayment::query()
                ->with(['shop.plan', 'invoice', 'reviewer', 'shopAddon'])
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->orderByRaw('submitted_at IS NULL')
                ->latest('submitted_at')
                ->latest('id')
                ->paginate(20),
            'pendingCount' => SubscriptionPayment::where('status', SubscriptionPaymentStatus::Pending->value)->whereNotNull('submitted_at')->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Payments</h1>
    </x-slot>

    <x-ui.card title="Signup payments" description="Bank transfers from self-serve signups. Confirming marks the invoice paid and activates the shop — or the add-on, for add-on payments.">
        <x-slot name="actions">
            <x-ui.select wire:model.live="status" class="w-44" aria-label="Filter by status">
                <option value="pending">Pending ({{ $pendingCount }} to review)</option>
                <option value="confirmed">Confirmed</option>
                <option value="rejected">Rejected</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-slot>

        @if ($payments->isEmpty())
            <x-ui.empty-state title="Nothing here" description="Payments appear when a shop signs up from the website." />
        @else
            <x-ui.table :headers="['Shop', 'For', 'Invoice', 'Amount', 'Reference', 'Status', '']">
                @foreach ($payments as $payment)
                    <x-ui.table-row wire:key="payment-{{ $payment->id }}">
                        <x-ui.table-cell>
                            <a href="{{ route('admin.shops.show', $payment->shop) }}" wire:navigate class="font-medium text-brand-700 hover:underline">{{ $payment->shop->name }}</a>
                            <p class="text-xs text-slate-500">{{ $payment->shop->email }} · {{ $payment->shop->phone }}</p>
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            @if ($payment->shopAddon)
                                <span class="font-medium text-slate-900">{{ $payment->shopAddon->name }}</span>
                                <p class="text-xs text-slate-500">Add-on</p>
                            @else
                                {{ $payment->shop->plan?->name ?? '—' }}
                                <p class="text-xs text-slate-500">Plan</p>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell>{{ $payment->invoice->invoice_number }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format((float) $payment->amount, 2) }}</x-ui.table-cell>
                        <x-ui.table-cell>
                            @if ($payment->reference)
                                <span class="font-mono text-xs">{{ $payment->reference }}</span>
                                <p class="text-xs text-slate-500">{{ $payment->submitted_at?->diffForHumans() }}</p>
                                @if ($payment->proof_path)
                                    <a href="{{ route('admin.payments.proof', $payment) }}" target="_blank" rel="noopener" class="text-xs font-semibold text-brand-700 hover:underline">View screenshot</a>
                                @endif
                            @else
                                <span class="text-xs text-slate-400">Not submitted yet</span>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            <x-ui.badge :variant="$payment->status->badge()">{{ $payment->status->label() }}</x-ui.badge>
                            @if ($payment->rejection_reason)
                                <p class="mt-1 max-w-[14rem] text-xs text-slate-500">{{ $payment->rejection_reason }}</p>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell>
                            @if ($payment->status === SubscriptionPaymentStatus::Pending)
                                <div class="flex justify-end gap-1">
                                    <x-ui.button size="sm" wire:click="confirm({{ $payment->id }})" wire:confirm="Confirm Rs {{ number_format((float) $payment->amount, 2) }} received from {{ $payment->shop->name }}? This marks the invoice paid and activates the shop.">Confirm</x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openReject({{ $payment->id }})">Reject</x-ui.button>
                                </div>
                            @elseif ($payment->reviewer)
                                <p class="text-right text-xs text-slate-500">by {{ $payment->reviewer->name }}<br>{{ $payment->reviewed_at?->format('d M Y') }}</p>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>

            <div class="mt-4">{{ $payments->links() }}</div>
        @endif
    </x-ui.card>

    <x-ui.modal name="reject-payment" max-width="md">
        <form wire:submit="reject" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Reject payment</h2>
            <p class="mt-1 text-sm text-slate-500">The shop owner sees this reason and can submit again.</p>
            <x-ui.field label="Reason" name="rejectReason" for="rejectReason" class="mt-5">
                <x-ui.input wire:model="rejectReason" id="rejectReason" maxlength="500" placeholder="e.g. No transfer found with this reference" />
            </x-ui.field>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button variant="secondary" x-on:click="$dispatch('close-modal', { name: 'reject-payment' })">Cancel</x-ui.button>
                <x-ui.button type="submit" variant="danger" wire:loading.attr="disabled" wire:target="reject">Reject</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
