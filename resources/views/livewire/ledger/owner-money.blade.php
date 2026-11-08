<?php

use App\Actions\OwnerMoney;
use App\Ledger\LedgerPoster;
use App\Livewire\Concerns\ChoosesPaymentSource;
use App\Livewire\Concerns\Toasts;
use App\Models\OwnerWithdrawal;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\WithholdingPayment;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Owner & Held Money')] class extends Component
{
    use ChoosesPaymentSource, Toasts;

    public string $kind = 'withdrawal';
    public string $amount = '';
    public string $paidOn = '';
    public string $note = '';

    public function boot(): void
    {
        abort_unless(Auth::user()->is_owner && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(): void
    {
        $this->paidOn = BatchExpiry::today();
        $this->paidFrom = 'cash';
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $list = fn (string $model) => $model::withoutGlobalScope(ExcludesCancelledScope::class)->latest('paid_on')->latest('id')->limit(25)->get();

        return [
            'running' => $shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null,
            'held' => OwnerMoney::withheldBalance($shop->id),
            'withdrawals' => $list(OwnerWithdrawal::class),
            'payments' => $list(WithholdingPayment::class),
        ];
    }

    public function save(): void
    {
        $this->validate([
            'kind' => ['required', 'in:withdrawal,withheld'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'paidOn' => ['required', 'date', 'before_or_equal:'.BatchExpiry::today()],
            'note' => ['nullable', 'string', 'max:255'],
        ], [], ['paidOn' => 'date']);

        [$method, $accountId] = $this->paidFromParts();
        $action = $this->kind === 'withdrawal' ? 'withdraw' : 'payOverWithheld';

        OwnerMoney::{$action}(Auth::user()->shop, (float) $this->amount, $this->paidOn, $method, $accountId, $this->note, Auth::id());

        $this->toastSuccess($this->kind === 'withdrawal' ? 'Withdrawal recorded.' : 'Payment recorded.');
        $this->reset(['amount', 'note']);
        $this->paidFrom = 'cash';
        app(LedgerPoster::class)->drain(Auth::user()->shop_id);
    }

    public function cancel(string $kind, int $id): void
    {
        $model = $kind === 'withdrawal' ? OwnerWithdrawal::class : WithholdingPayment::class;

        OwnerMoney::cancel($model::findOrFail($id), Auth::id());

        $this->toastSuccess('Cancelled.');
        app(LedgerPoster::class)->drain(Auth::user()->shop_id);
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Owner & Held Money</h1>
    </x-slot>

    <x-ledger.nav active="ledger.owner-money" />

    @unless ($running)
        <x-ui.empty-state title="The ledger isn't running" description="These are recorded in the ledger, so it has to be set up and running first." />
    @else
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-ui.card title="Record">
                <form wire:submit="save" class="space-y-4">
                    <x-ui.field label="What is this?" name="kind" for="moneyKind">
                        <x-ui.select wire:model.live="kind" id="moneyKind">
                            <option value="withdrawal">Owner took money out</option>
                            <option value="withheld">Paying over held salary</option>
                        </x-ui.select>
                    </x-ui.field>
                    @if ($kind === 'withheld')
                        <p class="text-sm text-slate-600">Rs {{ number_format($held, 2) }} is being held from paid salaries.</p>
                    @else
                        <p class="text-sm text-slate-600">This isn't a cost, so it stays out of Expenses and Net.</p>
                    @endif
                    <x-ui.field label="Amount" name="amount" for="moneyAmount">
                        <x-ui.input wire:model="amount" id="moneyAmount" type="number" min="0.01" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="Date" name="paidOn" for="moneyDate">
                        <x-ui.input wire:model="paidOn" id="moneyDate" type="date" />
                    </x-ui.field>
                    <x-ledger.paid-from :value="$paidFrom" />
                    <x-ui.field label="Note" name="note" for="moneyNote" help="Optional">
                        <x-ui.input wire:model="note" id="moneyNote" />
                    </x-ui.field>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Save</x-ui.button>
                </form>
            </x-ui.card>

            <div class="space-y-6 lg:col-span-2">
                @foreach ([['Owner withdrawals', 'withdrawal', $withdrawals], ['Held salary paid over', 'withheld', $payments]] as [$title, $type, $rows])
                    <x-ui.card :title="$title" :padding="false">
                        @if ($rows->isEmpty())
                            <p class="px-5 py-4 text-sm text-slate-500">Nothing yet.</p>
                        @else
                            <x-ui.table :headers="['Date', 'Amount', 'Note', '']">
                                @foreach ($rows as $row)
                                    <x-ui.table-row wire:key="{{ $type }}-{{ $row->id }}" @class(['opacity-50' => $row->isCancelled()])>
                                        <x-ui.table-cell>{{ $row->paid_on->format('d M Y') }}</x-ui.table-cell>
                                        <x-ui.table-cell class="font-medium text-slate-900">Rs {{ number_format($row->amount, 2) }}</x-ui.table-cell>
                                        <x-ui.table-cell>{{ $row->note ?? '—' }}</x-ui.table-cell>
                                        <x-ui.table-cell align="right">
                                            @if ($row->isCancelled())
                                                <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                                            @else
                                                <x-ui.button size="sm" variant="danger-ghost" wire:click="cancel('{{ $type }}', {{ $row->id }})" wire:confirm="Cancel this?">Cancel</x-ui.button>
                                            @endif
                                        </x-ui.table-cell>
                                    </x-ui.table-row>
                                @endforeach
                            </x-ui.table>
                        @endif
                    </x-ui.card>
                @endforeach
            </div>
        </div>
    @endunless
</div>
