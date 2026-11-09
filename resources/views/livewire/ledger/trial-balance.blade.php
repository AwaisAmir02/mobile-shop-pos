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
        <x-ui.page-header>Trial Balance</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.trial-balance" />

    <x-ui.error :messages="$errors->get('ledger')" class="mb-4" />

    @if ($shop->ledger_frozen_at)
        <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
            This ledger was frozen on {{ $shop->ledger_frozen_at->format('d M Y') }}, so these figures no longer change. Nothing since then has been posted, and restarting needs a new setup.
            <div class="mt-2">
                @if (auth()->user()->is_owner)
                    <x-ui.button href="{{ route('ledger.setup') }}" wire:navigate type="button" size="sm" variant="secondary">Start a New Ledger</x-ui.button>
                @endif
            </div>
        </div>
    @endif

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-line bg-surface-sunken px-4 py-3 text-sm text-ink-muted">{{ session('status') }}</div>
    @endif

    @if ($shop->ledger_ready_at === null && $shop->ledger_frozen_at === null)
        <x-ui.card title="Start the ledger">
            <p class="text-sm text-ink-muted">From the day it starts, new records post to the ledger automatically. Your existing screens, dues and reports don't change.</p>
            @if (auth()->user()->is_owner)
                <x-ui.button href="{{ route('ledger.setup') }}" wire:navigate type="button" class="mt-4">Set Up the Ledger</x-ui.button>
            @endif
        </x-ui.card>
    @else
        <x-ui.filter-bar>
            <x-ui.field label="As of" name="asOf" for="asOf" class="w-full sm:w-44">
                <x-ui.input wire:model.live="asOf" id="asOf" type="date" />
            </x-ui.field>

            <x-slot:actions>
                @if ($waiting > 0)
                    <x-ui.button type="button" size="sm" variant="secondary" wire:click="refresh">Post Waiting Records ({{ $waiting }})</x-ui.button>
                @endif
                <x-ui.export-dropdown />
            </x-slot:actions>
        </x-ui.filter-bar>

        @if ($failed > 0)
            <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger">
                {{ $failed }} {{ str('record')->plural($failed) }} couldn't post to the ledger, so these figures are incomplete. The records themselves are safe.
            </div>
        @endif

        @if ($rows->isEmpty())
            <x-ui.empty-state title="Nothing posted yet" description="New records show here as they post." />
        @else
            <x-ui.table stack :headers="['Account', 'Code', 'Debit', 'Credit']">
                @foreach ($rows as $row)
                    <x-ui.table-row wire:key="account-{{ $row['code'] }}">
                        <x-ui.table-cell primary class="font-medium"><a href="{{ route('ledger.account', $row['code']) }}" wire:navigate class="text-link hover:underline">{{ $row['name'] }}</a></x-ui.table-cell>
                        <x-ui.table-cell label="Code">{{ $row['code'] }}</x-ui.table-cell>
                        <x-ui.table-cell label="Debit">{{ $row['debit'] ? 'Rs '.number_format($row['debit'], 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Credit">{{ $row['credit'] ? 'Rs '.number_format($row['credit'], 2) : '—' }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
                <x-ui.table-row class="bg-surface-sunken/60">
                    <x-ui.table-cell primary class="font-semibold text-ink">Total</x-ui.table-cell>
                    <x-ui.table-cell label="Code">—</x-ui.table-cell>
                    <x-ui.table-cell label="Debit" class="font-semibold text-ink">Rs {{ number_format($totalDebit, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Credit" class="font-semibold text-ink">Rs {{ number_format($totalCredit, 2) }}</x-ui.table-cell>
                </x-ui.table-row>
            </x-ui.table>
        @endif
    @endif
</div>
