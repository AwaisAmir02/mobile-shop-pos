<?php

use App\Livewire\Concerns\LedgerReportScreen;
use App\Models\LedgerOutbox;
use App\ReportQueries\TrialBalanceReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Trial Balance')] class extends Component
{
    use LedgerReportScreen;

    public string $asOf = '';

    public function mount(): void
    {
        $this->catchUp();
    }

    public function refresh(): void
    {
        $this->catchUp();
    }

    protected function reportTitle(): string
    {
        return 'Trial Balance';
    }

    protected function reportFilters(): string
    {
        return 'As of '.($this->asOf ?: 'today');
    }

    protected function reportHeaders(): array
    {
        return ['Code', 'Account', 'Debit', 'Credit'];
    }

    protected function reportColumnTypes(): array
    {
        return ['string', 'string', 'currency', 'currency'];
    }

    protected function reportRows(bool $forExcel): array
    {
        return (new TrialBalanceReport(Auth::user()->shop, $this->asOf))->rows()
            ->map(fn (array $row) => [$row['code'], $row['name'], $this->money($row['debit'], $forExcel), $this->money($row['credit'], $forExcel)])
            ->all();
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $rows = (new TrialBalanceReport($shop, $this->asOf))->rows();
        $outbox = LedgerOutbox::where('shop_id', $shop->id);

        return [
            'shop' => $shop,
            'rows' => $rows,
            'totalDebit' => round($rows->sum('debit'), 2),
            'totalCredit' => round($rows->sum('credit'), 2),
            'waiting' => (clone $outbox)->count(),
            'failed' => (clone $outbox)->where('attempts', '>', 0)->count(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Trial Balance</h1>
    </x-slot>

    <x-ledger.nav active="ledger.trial-balance" />

    <x-ui.error :messages="$errors->get('ledger')" class="mb-4" />

    @if ($shop->ledger_frozen_at)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            This ledger was frozen on {{ $shop->ledger_frozen_at->format('d M Y') }}, so these figures no longer change. Nothing since then has been posted, and restarting needs a new setup.
            <div class="mt-2">
                @if (auth()->user()->is_owner)
                    <a href="{{ route('ledger.setup') }}" wire:navigate><x-ui.button type="button" size="sm" variant="secondary">Start a New Ledger</x-ui.button></a>
                @endif
            </div>
        </div>
    @endif

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ session('status') }}</div>
    @endif

    @if ($shop->ledger_ready_at === null && $shop->ledger_frozen_at === null)
        <x-ui.card title="Start the ledger">
            <p class="text-sm text-slate-600">From the day it starts, new records post to the ledger automatically. Your existing screens, dues and reports don't change.</p>
            @if (auth()->user()->is_owner)
                <a href="{{ route('ledger.setup') }}" wire:navigate><x-ui.button type="button" class="mt-4">Set Up the Ledger</x-ui.button></a>
            @endif
        </x-ui.card>
    @else
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.field label="As of" name="asOf" for="asOf" class="sm:max-w-[12rem]">
                <x-ui.input wire:model.live="asOf" id="asOf" type="date" />
            </x-ui.field>
            <div class="flex items-center gap-2">
                @if ($waiting > 0)
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="refresh">Post Waiting Records ({{ $waiting }})</x-ui.button>
                @endif
                <x-ui.export-dropdown />
            </div>
        </div>

        @if ($failed > 0)
            <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $failed }} {{ str('record')->plural($failed) }} couldn't post to the ledger, so these figures are incomplete. The records themselves are safe.
            </div>
        @endif

        @if ($rows->isEmpty())
            <x-ui.empty-state title="Nothing posted yet" description="New records show here as they post." />
        @else
            <x-ui.table :headers="['Code', 'Account', 'Debit', 'Credit']">
                @foreach ($rows as $row)
                    <x-ui.table-row wire:key="account-{{ $row['code'] }}">
                        <x-ui.table-cell>{{ $row['code'] }}</x-ui.table-cell>
                        <x-ui.table-cell class="font-medium"><a href="{{ route('ledger.account', $row['code']) }}" wire:navigate class="text-brand-700 hover:text-brand-800">{{ $row['name'] }}</a></x-ui.table-cell>
                        <x-ui.table-cell>{{ $row['debit'] ? 'Rs '.number_format($row['debit'], 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell>{{ $row['credit'] ? 'Rs '.number_format($row['credit'], 2) : '—' }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
                <x-ui.table-row>
                    <x-ui.table-cell></x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Total</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($totalDebit, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">Rs {{ number_format($totalCredit, 2) }}</x-ui.table-cell>
                </x-ui.table-row>
            </x-ui.table>
        @endif
    @endif
</div>
