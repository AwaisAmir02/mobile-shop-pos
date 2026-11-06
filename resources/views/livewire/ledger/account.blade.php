<?php

use App\Models\LedgerAccount;
use App\Models\LedgerLine;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Account Ledger')] class extends Component
{
    use WithPagination;

    public string $code;
    public string $from = '';
    public string $to = '';

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('ledger-reports') && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->account();
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['from', 'to'], true)) {
            $this->resetPage();
        }
    }

    protected function account(): LedgerAccount
    {
        return LedgerAccount::where('code', $this->code)->firstOrFail();
    }

    protected function lines()
    {
        return LedgerLine::query()
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_lines.ledger_account_id', $this->account()->id)
            ->where('ledger_entries.epoch', Auth::user()->shop->ledger_epoch)
            ->select('ledger_lines.*', 'ledger_entries.entry_date', 'ledger_entries.description');
    }

    public function with(): array
    {
        $opening = $this->from ? (int) $this->lines()->whereDate('ledger_entries.entry_date', '<', $this->from)->sum('amount_paise') : 0;
        $filtered = fn () => $this->lines()
            ->when($this->from, fn ($q) => $q->whereDate('ledger_entries.entry_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('ledger_entries.entry_date', '<=', $this->to));

        return [
            'account' => $this->account(),
            'opening' => $opening / 100,
            'closing' => ($opening + (int) $filtered()->sum('amount_paise')) / 100,
            'lines' => $filtered()->orderByDesc('ledger_entries.entry_date')->orderByDesc('ledger_lines.id')->paginate(25),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">{{ $account->name }}</h1>
            <a href="{{ route('ledger.trial-balance') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← Trial Balance</a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end">
        <x-ui.field label="From" name="from" for="accountFrom" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="from" id="accountFrom" type="date" />
        </x-ui.field>
        <x-ui.field label="To" name="to" for="accountTo" class="sm:max-w-[10rem]">
            <x-ui.input wire:model.live="to" id="accountTo" type="date" />
        </x-ui.field>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <x-ui.stat label="Opening" :value="'Rs '.number_format(abs($opening), 2).($opening < 0 ? ' Cr' : ' Dr')" />
        <x-ui.stat label="Closing" :value="'Rs '.number_format(abs($closing), 2).($closing < 0 ? ' Cr' : ' Dr')" />
    </div>

    @if ($lines->isEmpty())
        <x-ui.empty-state title="No entries" description="Nothing posted to this account in this period." />
    @else
        <x-ui.table :headers="['Date', 'Description', 'Debit', 'Credit']">
            @foreach ($lines as $line)
                <x-ui.table-row wire:key="line-{{ $line->id }}">
                    <x-ui.table-cell>{{ \Carbon\Carbon::parse($line->entry_date)->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="whitespace-normal">{{ $line->description }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $line->amount_paise > 0 ? 'Rs '.number_format($line->amount_paise / 100, 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $line->amount_paise < 0 ? 'Rs '.number_format(-$line->amount_paise / 100, 2) : '—' }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $lines->links() }}</div>
    @endif
</div>
