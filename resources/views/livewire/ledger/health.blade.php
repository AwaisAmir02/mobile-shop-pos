<?php

use App\Ledger\LedgerPoster;
use App\ReportQueries\LedgerHealthReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Ledger Health')] class extends Component
{
    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('ledger-reports') && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(): void
    {
        $shop = Auth::user()->shop;

        if ($shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null) {
            app(LedgerPoster::class)->drain($shop->id);
        }
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $report = new LedgerHealthReport($shop);

        return [
            'shop' => $shop,
            'checks' => $shop->ledger_ready_at ? $report->checks() : [],
            'counts' => $report->counts(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Ledger Health</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.health" />

    <x-ui.note>Compares the ledger with what your other screens say. Unexplained should be zero.</x-ui.note>

    @if ($counts['failed'] > 0)
        <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger">
            {{ $counts['failed'] }} {{ str('record')->plural($counts['failed']) }} couldn't post to the ledger. The records themselves are safe.
        </div>
    @endif

    @if ($counts['lines_with_no_cost'] > 0)
        <div class="mb-4 rounded-xl border border-warning/20 bg-warning-soft px-4 py-3 text-sm text-warning">
            {{ number_format($counts['lines_with_no_cost']) }} sold {{ str('line')->plural($counts['lines_with_no_cost']) }} have no cost, so they post no cost of goods sold and profit looks higher than it was.
        </div>
    @endif

    @if ($checks === [])
        <x-ui.empty-state title="The ledger hasn't started" description="Start it from the Trial Balance screen." />
    @else
        <x-ui.table stack :headers="['Check', 'Ledger', 'Records', 'Explained', 'Unexplained']">
            @foreach ($checks as $check)
                <x-ui.table-row wire:key="check-{{ $loop->index }}">
                    <x-ui.table-cell primary class="font-medium text-ink">{{ $check['label'] }}<p class="max-w-md whitespace-normal text-xs font-normal text-ink-faint">{{ $check['note'] }}</p></x-ui.table-cell>
                    <x-ui.table-cell label="Ledger">Rs {{ number_format($check['ledger'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Records">Rs {{ number_format($check['records'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Explained">Rs {{ number_format($check['explained'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Unexplained">
                        <span @class(['font-semibold', 'text-success' => abs($check['unexplained']) <= $check['tolerance'], 'text-danger' => abs($check['unexplained']) > $check['tolerance']])>Rs {{ number_format($check['unexplained'], 2) }}</span>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
