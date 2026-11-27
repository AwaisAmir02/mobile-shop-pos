<?php

use App\Actions\Cheques;
use App\Enums\ChequeDirection;
use App\Enums\ChequeStatus;
use App\Livewire\Concerns\Toasts;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\ShopAccount;
use App\Models\Vendor;
use App\Support\BatchExpiry;
use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * The cheque register (W6): cheques received from customers and issued to
 * vendors, most of them post-dated. Nothing is settled until one clears
 * (App\Actions\Cheques).
 */
new #[Layout('layouts.app')] #[Title('Cheques')] class extends Component
{
    use Toasts;

    #[Url(except: 'received')]
    public string $direction = 'received';

    #[Url(except: 'open')]
    public string $status = 'open';

    // Record a cheque
    public string $partyId = '';
    public string $chequeNumber = '';
    public string $bank = '';
    public string $amount = '';
    public string $chequeDate = '';
    public string $receivedOn = '';
    public string $note = '';
    public string $shopAccountId = '';

    // Deposit / clear / bounce
    public ?int $actingId = null;
    public string $action = '';
    public string $bounceReason = '';

    public function boot(): void
    {
        BranchContext::current()->shopWideForRequest();
    }

    public function mount(): void
    {
        $this->direction = ChequeDirection::tryFrom($this->direction)?->value ?? ChequeDirection::Received->value;
        $this->partyId = (string) request()->query('customer', '');

        if ($this->partyId !== '') {
            $this->openRecord();
        }
    }

    /** Received cheques are the Udhaar permission's; issued ones need Vendors too. */
    protected function canIssue(): bool
    {
        return Auth::user()->hasAccessTo('vendors');
    }

    public function updatedDirection(): void
    {
        if ($this->direction === ChequeDirection::Issued->value && ! $this->canIssue()) {
            $this->direction = ChequeDirection::Received->value;
        }
    }

    public function openRecord(): void
    {
        $this->reset(['chequeNumber', 'bank', 'amount', 'note', 'shopAccountId']);
        $this->chequeDate = BatchExpiry::today();
        $this->receivedOn = BatchExpiry::today();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cheque-form');
    }

    public function record(): void
    {
        abort_if($this->direction === ChequeDirection::Issued->value && ! $this->canIssue(), 403);
        $this->validate(Cheques::rules() + ['shopAccountId' => ['nullable', 'integer']], [], ['partyId' => $this->direction === ChequeDirection::Received->value ? 'customer' : 'vendor', 'chequeNumber' => 'cheque number', 'chequeDate' => 'cheque date', 'receivedOn' => 'date']);

        $cheque = Cheques::record([
            'direction' => $this->direction,
            'party_id' => (int) $this->partyId,
            'cheque_number' => $this->chequeNumber,
            'bank' => $this->bank,
            'amount' => $this->amount,
            'cheque_date' => $this->chequeDate,
            'received_on' => $this->receivedOn,
            'note' => $this->note,
            'shop_account_id' => $this->shopAccountId !== '' ? (int) $this->shopAccountId : null,
        ], Auth::id());

        $this->dispatch('close-modal', name: 'cheque-form');
        $this->partyId = '';
        $this->toastSuccess($cheque->label().' recorded. It settles nothing until it clears.');
    }

    public function openAction(int $id, string $action): void
    {
        $cheque = Cheque::findOrFail($id);
        abort_if(! $cheque->isReceived() && ! $this->canIssue(), 403);

        $this->actingId = $cheque->id;
        $this->action = $action;
        $this->bounceReason = '';
        $this->shopAccountId = (string) ($cheque->shop_account_id ?? '');
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cheque-action');
    }

    public function confirmAction(): void
    {
        $cheque = Cheque::findOrFail($this->actingId);
        abort_if(! $cheque->isReceived() && ! $this->canIssue(), 403);
        $account = $this->shopAccountId !== '' ? (int) $this->shopAccountId : null;

        match ($this->action) {
            'deposit' => Cheques::deposit($cheque, $account ?? 0),
            'clear' => Cheques::clear($cheque, $account, Auth::id()),
            'bounce' => Cheques::bounce($cheque, $this->bounceReason, Auth::id()),
            default => Cheques::cancel($cheque),
        };

        $this->dispatch('close-modal', name: 'cheque-action');
        $this->toastSuccess(match ($this->action) {
            'deposit' => 'Marked deposited.',
            'clear' => 'Cleared. The money has been put against what is owed.',
            'bounce' => $cheque->status === ChequeStatus::Cleared ? 'Marked bounced. What it paid is owed again.' : 'Marked bounced.',
            default => 'Cheque cancelled.',
        });
    }

    public function with(): array
    {
        $today = BatchExpiry::today();
        $base = Cheque::query()->where('direction', $this->direction);

        return [
            'cheques' => (clone $base)
                ->when($this->status === 'open', fn ($q) => $q->whereIn('status', array_column(ChequeStatus::open(), 'value')))
                ->when(! in_array($this->status, ['open', 'all'], true), fn ($q) => $q->where('status', $this->status))
                ->with(['customer', 'vendor', 'shopAccount'])
                ->orderBy($this->status === 'open' ? 'cheque_date' : 'id', $this->status === 'open' ? 'asc' : 'desc')
                ->limit(200)
                ->get(),
            'inHand' => (float) (clone $base)->whereIn('status', array_column(ChequeStatus::open(), 'value'))->sum('amount'),
            'dueNow' => (float) (clone $base)->whereIn('status', array_column(ChequeStatus::open(), 'value'))->where('cheque_date', '<=', now()->parse($today)->addDays(7)->toDateString())->sum('amount'),
            'bouncedMonth' => (float) (clone $base)->where('status', ChequeStatus::Bounced->value)->where('bounced_on', '>=', now()->parse($today)->startOfMonth()->toDateString())->sum('amount'),
            'today' => $today,
            'parties' => $this->direction === ChequeDirection::Received->value ? Customer::query()->orderBy('name')->get() : Vendor::query()->orderBy('name')->get(),
            'accounts' => ShopAccount::query()->orderBy('name')->get(),
            'canIssue' => $this->canIssue(),
            'acting' => $this->actingId ? Cheque::find($this->actingId) : null,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Post-dated cheques in hand and given out. A cheque settles nothing until it clears.">Cheques</x-ui.page-header>
    </x-slot>

    <x-ui.stat-row>
        <x-ui.stat :label="$direction === ChequeDirection::Received->value ? 'In hand' : 'Given, not cleared'" value="Rs {{ number_format($inHand, 2) }}" sub="Not yet cleared" />
        <x-ui.stat label="Due within 7 days" value="Rs {{ number_format($dueNow, 2) }}" sub="Cheque date today or in the next week" />
        <x-ui.stat label="Bounced this month" value="Rs {{ number_format($bouncedMonth, 2) }}" />
    </x-ui.stat-row>

    <x-ui.filter-bar :active="$status !== 'open' ? 1 : 0">
        @if ($canIssue)
            <x-ui.field label="Cheques" for="cheque-direction" class="w-full sm:w-auto">
                <x-ui.segmented name="cheque-direction" wire:model.live="direction" :options="['received' => 'Received', 'issued' => 'Issued to vendors']" label="Cheques" />
            </x-ui.field>
        @endif
        <x-ui.field label="Status" for="cheque-status" class="w-full sm:w-48">
            <x-ui.select wire:model.live="status" id="cheque-status">
                <option value="open">Not cleared yet</option>
                <option value="cleared">Cleared</option>
                <option value="bounced">Bounced</option>
                <option value="cancelled">Cancelled</option>
                <option value="all">All</option>
            </x-ui.select>
        </x-ui.field>
        <x-slot:actions>
            <x-ui.button wire:click="openRecord" class="shrink-0" data-record-cheque>Record Cheque</x-ui.button>
        </x-slot:actions>
    </x-ui.filter-bar>

    @if ($cheques->isEmpty())
        <x-ui.empty-state title="No cheques here" description="Record a cheque when a customer hands one over, or when you give one to a vendor." />
    @else
        <x-ui.table stack :headers="['Cheque', $direction === ChequeDirection::Received->value ? 'From' : 'To', 'Cheque date', 'Amount', 'Status', '']">
            @foreach ($cheques as $cheque)
                @php($late = $cheque->isOpen() && $cheque->cheque_date->toDateString() <= $today)
                <x-ui.table-row wire:key="cheque-{{ $cheque->id }}" data-cheque="{{ $cheque->id }}">
                    <x-ui.table-cell primary>
                        <span class="font-semibold text-ink">{{ $cheque->cheque_number }}</span>
                        <span class="block text-xs text-ink-faint">{{ $cheque->bank }}{{ $cheque->shopAccount ? ' · '.$cheque->shopAccount->name : '' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="{{ $direction === ChequeDirection::Received->value ? 'From' : 'To' }}">{{ $cheque->partyName() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Cheque date" @class(['font-semibold text-warning' => $late])>{{ $cheque->cheque_date->format('d M Y') }}{{ $late ? ' · can be banked' : '' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Amount" class="font-semibold tabular-nums text-ink">Rs {{ number_format($cheque->amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <x-ui.badge :variant="match ($cheque->status) { ChequeStatus::Cleared => 'success', ChequeStatus::Bounced => 'danger', ChequeStatus::Cancelled => 'default', default => 'warning' }">{{ $cheque->statusLabel() }}</x-ui.badge>
                        @if ($cheque->bounce_reason)
                            <span class="block text-xs text-ink-faint">{{ $cheque->bounce_reason }}</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        @if ($cheque->isOpen() || $cheque->status === ChequeStatus::Cleared)
                            <x-ui.row-actions label="Actions for cheque {{ $cheque->cheque_number }}">
                                @if ($cheque->isReceived() && $cheque->status === ChequeStatus::Pending)
                                    <x-ui.menu-item wire:click="openAction({{ $cheque->id }}, 'deposit')">Deposited</x-ui.menu-item>
                                @endif
                                @if ($cheque->isOpen())
                                    <x-ui.menu-item wire:click="openAction({{ $cheque->id }}, 'clear')">Cleared</x-ui.menu-item>
                                @endif
                                <x-ui.menu-item wire:click="openAction({{ $cheque->id }}, 'bounce')">Bounced</x-ui.menu-item>
                                @if ($cheque->isOpen())
                                    <x-ui.menu-divider />
                                    <x-ui.menu-item danger wire:click="openAction({{ $cheque->id }}, 'cancel')">Cancel</x-ui.menu-item>
                                @endif
                            </x-ui.row-actions>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <x-ui.modal name="cheque-form" max-width="lg">
        <form wire:submit="record" class="p-6">
            <h2 class="text-lg font-bold text-ink">{{ $direction === ChequeDirection::Received->value ? 'Cheque Received' : 'Cheque Issued' }}</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ $direction === ChequeDirection::Received->value ? 'Nothing is paid off until it clears. Then it pays their udhaar, then their oldest bills.' : 'The vendor is paid when it clears, oldest stock first.' }}</p>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-ui.field :label="$direction === ChequeDirection::Received->value ? 'Customer' : 'Vendor'" name="partyId" for="partyId" class="sm:col-span-2">
                    <div wire:key="party-{{ $direction }}-{{ $partyId }}">
                        <x-ui.select wire:model="partyId" id="partyId">
                            <option value="">Choose…</option>
                            @foreach ($parties as $party)
                                <option value="{{ $party->id }}">{{ $party instanceof \App\Models\Customer ? $party->displayName() : $party->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </x-ui.field>
                <x-ui.field label="Cheque number" name="chequeNumber" for="chequeNumber">
                    <x-ui.input wire:model="chequeNumber" id="chequeNumber" maxlength="40" />
                </x-ui.field>
                <x-ui.field label="Bank" name="bank" for="bank">
                    <x-ui.input wire:model="bank" id="bank" maxlength="80" placeholder="e.g. HBL, Meezan" />
                </x-ui.field>
                <x-ui.field label="Amount (Rs)" name="amount" for="chequeAmount">
                    <x-ui.input wire:model="amount" id="chequeAmount" type="number" min="1" step="0.01" />
                </x-ui.field>
                <x-ui.field label="Cheque date" name="chequeDate" for="chequeDate" help="The date written on it">
                    <x-ui.input wire:model="chequeDate" id="chequeDate" type="date" />
                </x-ui.field>
                <x-ui.field :label="$direction === ChequeDirection::Received->value ? 'Received on' : 'Given on'" name="receivedOn" for="receivedOn">
                    <x-ui.input wire:model="receivedOn" id="receivedOn" type="date" max="{{ $today }}" />
                </x-ui.field>
                @if ($direction === ChequeDirection::Issued->value)
                    <x-ui.field label="Drawn on" name="shopAccountId" for="drawnOn">
                        <x-ui.select wire:model="shopAccountId" id="drawnOn">
                            <option value="">Choose an account…</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif
                <x-ui.field label="Note" name="note" for="chequeNote" class="sm:col-span-2">
                    <x-ui.input wire:model="note" id="chequeNote" maxlength="255" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cheque-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit">Record Cheque</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cheque-action" max-width="md">
        <form wire:submit="confirmAction" class="p-6">
            @if ($acting)
                <h2 class="text-lg font-bold text-ink">{{ ['deposit' => 'Deposited', 'clear' => 'Cleared', 'bounce' => 'Bounced', 'cancel' => 'Cancel'][$action] ?? '' }}: {{ $acting->label() }}</h2>
                <p class="mt-1 text-sm text-ink-muted">Rs {{ number_format($acting->amount, 2) }} · {{ $acting->partyName() }}</p>
                <div class="mt-5 space-y-4">
                    @if (in_array($action, ['deposit', 'clear'], true))
                        <x-ui.field :label="$acting->isReceived() ? 'Into account' : 'Drawn on'" name="shopAccountId" for="actionAccount">
                            <x-ui.select wire:model="shopAccountId" id="actionAccount">
                                <option value="">Choose an account…</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                        @if ($action === 'clear')
                            <p class="text-sm text-ink-muted">{{ $acting->isReceived() ? 'It pays their udhaar loan first, then their oldest unpaid bills. Anything more is kept as advance.' : 'It pays this vendor\'s oldest unpaid stock. Anything more stays as credit with them.' }}</p>
                        @endif
                    @elseif ($action === 'bounce')
                        <x-ui.field label="Why it bounced" name="bounceReason" for="bounceReason" help="As the bank wrote it">
                            <x-ui.input wire:model="bounceReason" id="bounceReason" maxlength="255" placeholder="e.g. Insufficient funds" />
                        </x-ui.field>
                        @if ($acting->status === ChequeStatus::Cleared)
                            <x-ui.alert variant="warning">It had cleared, so what it paid will be owed again, and the money leaves {{ $acting->shopAccount?->name ?? 'the account' }} today.</x-ui.alert>
                        @endif
                    @else
                        <p class="text-sm text-ink-muted">For a cheque recorded by mistake or handed back. It settled nothing.</p>
                    @endif
                    @error('cheque')<p class="text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'cheque-action' })">Back</x-ui.button>
                    <x-ui.button type="submit" :variant="in_array($action, ['bounce', 'cancel'], true) ? 'danger' : 'primary'">Confirm</x-ui.button>
                </div>
            @endif
        </form>
    </x-ui.modal>
</div>
