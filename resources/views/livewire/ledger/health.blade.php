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
        abort_unless(Auth::user()->is_owner && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(): void
    {
        $shop = Auth::user()->shop;

        if ($shop->ledger_started_at !== null && $shop->ledger_frozen_at === null) {
            app(LedgerPoster::class)->drain($shop->id);
        }
    }

    public function with(): array
    {
        $shop = Auth::user()->shop;
        $report = new LedgerHealthReport($shop);

        return [
            'shop' => $shop,
            'checks' => $shop->ledger_started_at ? $report->checks() : [],
            'counts' => $report->counts(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Ledger Health</h1>
    </x-slot>

    <x-ledger.nav active="ledger.health" />

    <p class="mb-4 text-sm text-slate-600">Compares the ledger with what your other screens say. Unexplained should be zero.</p>

    @if ($counts['failed'] > 0)
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $counts['failed'] }} {{ str('record')->plural($counts['failed']) }} couldn't post to the ledger. The records themselves are safe.
        </div>
    @endif

    @if ($counts['lines_with_no_cost'] > 0)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ number_format($counts['lines_with_no_cost']) }} sold {{ str('line')->plural($counts['lines_with_no_cost']) }} have no cost, so they post no cost of goods sold and profit looks higher than it was.
        </div>
    @endif

    @if ($checks === [])
        <x-ui.empty-state title="The ledger hasn't started" description="Start it from the Trial Balance screen." />
    @else
        <x-ui.table :headers="['Check', 'Ledger', 'Records', 'Explained', 'Unexplained']">
            @foreach ($checks as $check)
                <x-ui.table-row wire:key="check-{{ $loop->index }}">
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $check['label'] }}<p class="max-w-md whitespace-normal text-xs font-normal text-slate-500">{{ $check['note'] }}</p></x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($check['ledger'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($check['records'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>Rs {{ number_format($check['explained'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        <span @class(['font-semibold', 'text-emerald-700' => abs($check['unexplained']) <= $check['tolerance'], 'text-red-700' => abs($check['unexplained']) > $check['tolerance']])>Rs {{ number_format($check['unexplained'], 2) }}</span>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
