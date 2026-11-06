<?php

use App\Ledger\LedgerPoster;
use App\Livewire\Concerns\Toasts;
use App\Models\LedgerOutbox;
use App\ReportQueries\TrialBalanceReport;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Trial Balance')] class extends Component
{
    use Toasts;

    public string $asOf = '';

    /** Owner only until the ledger's reports are finished. */
    public function boot(): void
    {
        abort_unless(Auth::user()->is_owner && Auth::user()->shop?->ledger_enabled, 403);
    }

    public function mount(): void
    {
        $this->catchUp();
    }

    /** Figures on screen shouldn't wait for the next sweep. */
    protected function catchUp(): void
    {
        $shop = Auth::user()->shop;

        if ($shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null) {
            app(LedgerPoster::class)->drain($shop->id);
        }
    }

    public function refresh(): void
    {
        $this->catchUp();
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
                <a href="{{ route('ledger.setup') }}" wire:navigate><x-ui.button type="button" size="sm" variant="secondary">Start a New Ledger</x-ui.button></a>
            </div>
        </div>
    @endif

    @if (session('status'))
        <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">{{ session('status') }}</div>
    @endif

    @if ($shop->ledger_ready_at === null && $shop->ledger_frozen_at === null)
        <x-ui.card title="Start the ledger">
            <p class="text-sm text-slate-600">From the day it starts, new records post to the ledger automatically. Your existing screens, dues and reports don't change.</p>
            <a href="{{ route('ledger.setup') }}" wire:navigate><x-ui.button type="button" class="mt-4">Set Up the Ledger</x-ui.button></a>
        </x-ui.card>
    @else
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <x-ui.field label="As of" name="asOf" for="asOf" class="sm:max-w-[12rem]">
                <x-ui.input wire:model.live="asOf" id="asOf" type="date" />
            </x-ui.field>
            @if ($waiting > 0)
                <x-ui.button type="button" size="sm" variant="secondary" wire:click="refresh">Post Waiting Records ({{ $waiting }})</x-ui.button>
            @endif
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
