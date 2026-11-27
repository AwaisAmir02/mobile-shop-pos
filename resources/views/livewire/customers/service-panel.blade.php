<?php

use App\Actions\CustomerDeposits;
use App\Actions\Memberships;
use App\Enums\RecordStatus;
use App\Enums\ServiceTradeTool;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\CustomerDeposit;
use App\Models\CustomerNote;
use App\ReportQueries\ClientVisits;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

/**
 * A client's page for a service business (embedded in customers.show): their
 * membership standing, notes (pinned first), visit history with who served
 * them, bookings, check-ins, and deposits held (refund or keep).
 */
new class extends Component
{
    use PicksPaymentMethod, Toasts;

    public int $customerId;
    public string $note = '';
    public ?int $refundId = null;
    public string $refundAmount = '';

    public function mount(int $customerId): void
    {
        $this->customerId = Customer::findOrFail($customerId)->id;
        $this->paymentMethod = 'cash';
    }

    protected function tool(ServiceTradeTool $tool): bool
    {
        return (bool) Auth::user()->shop?->usesTool($tool);
    }

    public function addNote(): void
    {
        abort_unless($this->tool(ServiceTradeTool::ClientNotes), 404);
        $this->validate(['note' => ['required', 'string', 'max:2000']]);
        CustomerNote::create(['customer_id' => $this->customerId, 'body' => trim($this->note), 'user_id' => Auth::id()]);
        $this->note = '';
    }

    public function togglePin(int $id): void
    {
        $note = CustomerNote::where('customer_id', $this->customerId)->findOrFail($id);
        $note->update(['pinned' => ! $note->pinned]);
    }

    public function deleteNote(int $id): void
    {
        CustomerNote::where('customer_id', $this->customerId)->findOrFail($id)->delete();
    }

    public function openRefund(int $depositId): void
    {
        $deposit = CustomerDeposit::where('customer_id', $this->customerId)->findOrFail($depositId);
        $this->refundId = $deposit->id;
        $this->refundAmount = (string) $deposit->remaining();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'refund-deposit');
    }

    public function refund(): void
    {
        [$method, $account] = $this->chosenPaymentMethod();
        CustomerDeposits::refund(CustomerDeposit::findOrFail($this->refundId), (float) $this->refundAmount, now('Asia/Karachi')->toDateString(), $method, $account);
        $this->dispatch('close-modal', name: 'refund-deposit');
        $this->toastSuccess('Deposit refunded.');
    }

    public function forfeit(int $depositId): void
    {
        CustomerDeposits::forfeit(CustomerDeposit::where('customer_id', $this->customerId)->findOrFail($depositId), now('Asia/Karachi')->toDateString());
        $this->toastSuccess('Kept by the shop.');
    }

    public function with(): array
    {
        $customer = Customer::findOrFail($this->customerId);
        $visits = new ClientVisits($this->customerId);

        return [
            'customer' => $customer,
            'notesOn' => $this->tool(ServiceTradeTool::ClientNotes),
            'notes' => CustomerNote::query()->with('user')->where('customer_id', $this->customerId)->orderByDesc('pinned')->latest('id')->get(),
            'standing' => $this->tool(ServiceTradeTool::Memberships) ? Memberships::standing($customer) : null,
            'memberships' => $this->tool(ServiceTradeTool::Memberships) ? Memberships::forCustomer($this->customerId) : collect(),
            'services' => $visits->services(),
            'appointments' => $this->tool(ServiceTradeTool::Appointments) ? $visits->appointments() : collect(),
            'checkIns' => $this->tool(ServiceTradeTool::CheckIns) ? $visits->checkIns() : collect(),
            'deposits' => $this->tool(ServiceTradeTool::Deposits) ? CustomerDeposit::query()->where('customer_id', $this->customerId)->where('status', RecordStatus::Active->value)->latest('id')->get() : collect(),
            'paymentAccounts' => $this->paymentAccounts(),
        ];
    }
}; ?>

<div class="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2" data-service-panel>
    @if ($standing)
        <x-ui.card title="Membership" data-client-membership>
            <p class="text-sm"><x-ui.badge :variant="$standing['standing'] === \App\Enums\MemberStanding::Active ? 'success' : 'danger'">{{ $standing['standing']->label() }}</x-ui.badge>
                @if ($customer->member_no) <span class="ml-2 text-xs text-ink-faint">Member no. {{ $customer->member_no }}</span>@endif</p>
            <ul class="mt-3 space-y-1 text-sm">
                @foreach ($memberships as $membership)
                    <li class="flex justify-between gap-3"><span class="text-ink">{{ $membership->plan_name }}</span><span class="text-ink-muted">{{ $membership->starts_on->format('d M') }}{{ $membership->ends_on ? ' – '.$membership->ends_on->format('d M Y') : '' }}{{ $membership->sessionsLeft() !== null ? ' · '.$membership->sessionsLeft().' left' : '' }}</span></li>
                @endforeach
            </ul>
            @if ($standing['membership']?->membership_plan_id)
                <x-ui.button size="sm" class="mt-3" href="{{ route('sales.index', ['renew' => $standing['membership']->id]) }}">Renew</x-ui.button>
            @endif
        </x-ui.card>
    @endif

    @if ($notesOn)
        <x-ui.card title="Notes" data-client-notes>
            <form wire:submit="addNote" class="flex gap-2">
                <x-ui.input wire:model="note" placeholder="Colour formula, allergy, goal…" aria-label="Note" />
                <x-ui.button type="submit" size="sm">Add</x-ui.button>
            </form>
            <x-ui.error :messages="$errors->get('note')" />
            <ul class="mt-3 divide-y divide-line">
                @foreach ($notes as $item)
                    <li wire:key="note-{{ $item->id }}" class="py-2 text-sm">
                        <p class="text-ink">@if ($item->pinned)<span class="font-semibold text-link">Pinned:</span> @endif{{ $item->body }}</p>
                        <p class="text-xs text-ink-faint">{{ $item->user?->name }} · {{ $item->created_at->timezone('Asia/Karachi')->format('d M Y') }} ·
                            <button type="button" wire:click="togglePin({{ $item->id }})" class="text-link hover:underline">{{ $item->pinned ? 'Unpin' : 'Pin' }}</button> ·
                            <button type="button" wire:click="deleteNote({{ $item->id }})" wire:confirm="Delete this note?" class="text-danger hover:underline">Delete</button></p>
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.card title="Visits" :padding="false" data-client-visits>
        @if ($services->isEmpty())
            <p class="px-5 py-6 text-sm text-ink-faint">No services yet.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($services as $visit)
                    <li class="flex justify-between gap-3 px-5 py-2 text-sm">
                        <span><span class="text-ink">{{ $visit['name'] }}</span><span class="block text-xs text-ink-faint">{{ \Carbon\Carbon::parse($visit['date'])->format('d M Y') }}{{ $visit['by'] ? ' · by '.$visit['by'] : '' }} · <a href="{{ route('sales.show', $visit['sale_id']) }}" class="text-link hover:underline" wire:navigate>{{ $visit['invoice'] }}</a></span></span>
                        <span class="tabular-nums text-ink">Rs {{ number_format($visit['amount'], 0) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>

    @if ($appointments->isNotEmpty() || $checkIns->isNotEmpty())
        <x-ui.card title="Bookings & check-ins" :padding="false">
            <ul class="divide-y divide-line text-sm">
                @foreach ($appointments as $appointment)
                    <li class="px-5 py-2"><span class="text-ink">{{ $appointment->appointment_date->format('d M Y') }}{{ $appointment->starts_at ? ' '.$appointment->starts_at->timezone('Asia/Karachi')->format('g:i A') : '' }} · {{ $appointment->servicesLabel() }}</span> <span class="text-xs text-ink-faint">{{ $appointment->status->label() }}{{ $appointment->employee ? ' · '.$appointment->employee->name : '' }}</span></li>
                @endforeach
                @foreach ($checkIns as $checkIn)
                    <li class="px-5 py-2 text-ink-muted">Checked in {{ $checkIn->checked_in_at->timezone('Asia/Karachi')->format('d M, g:i A') }} · {{ $checkIn->source->label() }}</li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    @if ($deposits->isNotEmpty())
        <x-ui.card title="Deposits" :padding="false" data-client-deposits>
            <ul class="divide-y divide-line">
                @foreach ($deposits as $deposit)
                    @php($left = $deposit->remaining())
                    <li wire:key="deposit-{{ $deposit->id }}" class="flex items-center justify-between gap-3 px-5 py-2 text-sm">
                        <span><span class="text-ink">{{ $deposit->number() }} · Rs {{ number_format((float) $deposit->amount, 0) }}</span><span class="block text-xs text-ink-faint">{{ $deposit->received_on->format('d M Y') }} · Rs {{ number_format($left, 0) }} held</span></span>
                        @if ($left > 0)
                            <span class="flex gap-1">
                                <x-ui.button size="sm" variant="secondary" wire:click="openRefund({{ $deposit->id }})">Refund</x-ui.button>
                                <x-ui.button size="sm" variant="danger-ghost" wire:click="forfeit({{ $deposit->id }})" confirm="Keep the Rs {{ number_format($left, 0) }} left on this deposit? It becomes the shop's income.">Keep</x-ui.button>
                            </span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @endif

    <x-ui.modal name="refund-deposit" max-width="sm">
        <form wire:submit="refund" class="space-y-4 p-6">
            <h2 class="text-lg font-bold text-ink">Refund deposit</h2>
            <x-ui.field label="Amount (Rs)" name="refundAmount" for="refundAmount"><x-ui.input wire:model="refundAmount" id="refundAmount" type="number" min="1" step="0.01" /></x-ui.field>
            <x-sales.payment-method-picker :accounts="$paymentAccounts" :value="$paymentMethod" amount="refundAmount" />
            <div class="flex justify-end gap-3"><x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'refund-deposit' })">Cancel</x-ui.button><x-ui.button type="submit">Refund</x-ui.button></div>
        </form>
    </x-ui.modal>
</div>
