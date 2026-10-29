<?php

use App\Enums\PartyLedgerDirection;
use App\Enums\PartyLedgerEntryKind;
use App\Enums\RecordStatus;
use App\Exports\TableExport;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Sale;
use App\Models\UdhaarTransaction;
use App\Services\TableExportService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Party Ledger')] class extends Component
{
    use GuardsExportSize, Toasts;

    #[Url(as: 'customer')]
    public ?int $customerId = null;

    public string $from = '';
    public string $to = '';

    public string $entryKind = 'adjustment';
    public string $entryDirection = 'debit';
    public string $entryAmount = '';
    public string $entryDate = '';
    public string $entryNote = '';

    public ?int $cancelingEntryId = null;
    public string $cancelReason = '';

    public function mount(): void
    {
        $this->from = now()->subMonthsNoOverflow(2)->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
        $this->entryDate = now()->toDateString();
    }

    /**
     * Customer lookups go through the tenant ShopScope, so an id from
     * another shop simply resolves to nothing.
     */
    protected function customer(): ?Customer
    {
        return $this->customerId ? Customer::find($this->customerId) : null;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function range(): array
    {
        try {
            $from = Carbon::parse($this->from)->startOfDay();
        } catch (\Throwable) {
            $from = now()->subMonthsNoOverflow(2)->startOfMonth();
        }

        try {
            $to = Carbon::parse($this->to)->endOfDay();
        } catch (\Throwable) {
            $to = now()->endOfDay();
        }

        return $from->lte($to) ? [$from, $to] : [$to->copy()->startOfDay(), $from->copy()->endOfDay()];
    }

    protected function rangeLabel(): string
    {
        [$from, $to] = $this->range();

        return $from->format('d M Y').' – '.$to->format('d M Y');
    }

    /**
     * One running-balance section from a list of dated movements: everything
     * before the range is folded into "brought forward", movements after the
     * range are left out, and the rest are listed in order.
     *
     * @param  Collection<int, array{date: Carbon, sort: string, debit: float, credit: float}>  $movements
     */
    protected function section(Collection $movements): array
    {
        [$from, $to] = $this->range();

        $movements = $movements->sortBy('sort')->values();

        $opening = $movements
            ->filter(fn (array $m) => $m['date']->lt($from))
            ->sum(fn (array $m) => $m['debit'] - $m['credit']);

        $running = $opening;
        $rows = $movements
            ->filter(fn (array $m) => $m['date']->between($from, $to))
            ->map(function (array $m) use (&$running) {
                $running += $m['debit'] - $m['credit'];

                return $m + ['balance' => $running];
            })
            ->values();

        return [
            'opening' => (float) $opening,
            'rows' => $rows,
            'debits' => (float) $rows->sum('debit'),
            'credits' => (float) $rows->sum('credit'),
            'closing' => (float) $running,
        ];
    }

    protected function sortKey(Carbon $date, int ...$parts): string
    {
        return $date->format('Ymd').collect($parts)->map(fn (int $p) => '-'.str_pad((string) $p, 10, '0', STR_PAD_LEFT))->implode('');
    }

    /**
     * Sales (debit) and their payments (credit). Cancelled sales — and so
     * their payments — are excluded by the Sale global scope, the same way
     * Customer::salesOutstandingBalance() excludes them.
     */
    protected function salesMovements(Customer $customer): Collection
    {
        $canOpen = Auth::user()->hasAccessTo('sales');

        return Sale::where('customer_id', $customer->id)->with('payments')->get()
            ->flatMap(function (Sale $sale) use ($canOpen) {
                $date = $sale->created_at->copy()->startOfDay();
                $url = $canOpen ? route('sales.show', $sale) : null;

                $movements = [[
                    'key' => 'sale-'.$sale->id,
                    'date' => $date,
                    'sort' => $this->sortKey($date, $sale->id, 0, 0),
                    'description' => 'Sale '.$sale->invoiceNumber(),
                    'detail' => null,
                    'url' => $url,
                    'side' => 'debit',
                    'debit' => (float) $sale->total,
                    'credit' => 0.0,
                    'entry' => null,
                ]];

                foreach ($sale->payments as $payment) {
                    if ((float) $payment->amount <= 0) {
                        continue;
                    }

                    $movements[] = [
                        'key' => 'sale-payment-'.$payment->id,
                        'date' => $payment->payment_date->copy()->startOfDay(),
                        'sort' => $this->sortKey($payment->payment_date, $sale->id, 1, $payment->id),
                        'description' => 'Payment received',
                        'detail' => $sale->invoiceNumber(),
                        'url' => $url,
                        'side' => 'credit',
                        'debit' => 0.0,
                        'credit' => (float) $payment->amount,
                        'entry' => null,
                    ];
                }

                return $movements;
            });
    }

    /**
     * Udhaar transactions, including reversal rows — the same set that makes
     * up Customer::udhaarBalance(). Cancelled ones are excluded by the
     * HasCancellation global scope.
     */
    protected function udhaarMovements(Customer $customer): Collection
    {
        $url = Auth::user()->hasAccessTo('udhaar') ? route('udhaar.show', $customer) : null;

        return $customer->udhaarTransactions->map(fn (UdhaarTransaction $transaction) => [
            'key' => 'udhaar-'.$transaction->id,
            'date' => $transaction->transaction_date->copy()->startOfDay(),
            'sort' => $this->sortKey($transaction->transaction_date, $transaction->id),
            'description' => $transaction->type->label(),
            'detail' => $transaction->note,
            'url' => $url,
            'side' => $transaction->type->sign() > 0 ? 'debit' : 'credit',
            'debit' => $transaction->type->sign() > 0 ? (float) $transaction->amount : 0.0,
            'credit' => $transaction->type->sign() < 0 ? (float) $transaction->amount : 0.0,
            'entry' => null,
        ]);
    }

    protected function manualMovements(Customer $customer): Collection
    {
        return PartyLedgerEntry::where('customer_id', $customer->id)->with('user')->get()
            ->map(fn (PartyLedgerEntry $entry) => [
                'key' => 'entry-'.$entry->id,
                'date' => $entry->entry_date->copy()->startOfDay(),
                'sort' => $this->sortKey($entry->entry_date, $entry->id),
                'description' => $entry->kind->label(),
                'detail' => $entry->note.($entry->user ? ' · by '.$entry->user->name : ''),
                'url' => null,
                'side' => $entry->direction->value,
                'debit' => $entry->direction === PartyLedgerDirection::Debit ? (float) $entry->amount : 0.0,
                'credit' => $entry->direction === PartyLedgerDirection::Credit ? (float) $entry->amount : 0.0,
                'entry' => $entry,
            ]);
    }

    /**
     * Sales, Udhaar, and Opening & adjustments are three separate balances
     * and are never combined or netted against each other — the first two
     * always match the customer profile and Udhaar screen exactly.
     */
    protected function statement(Customer $customer): array
    {
        return [
            'sales' => $this->section($this->salesMovements($customer)),
            'udhaar' => $this->section($this->udhaarMovements($customer)),
            'manual' => $this->section($this->manualMovements($customer)),
        ];
    }

    public function with(): array
    {
        $customer = $this->customer();
        [$rangeFrom, $rangeTo] = $this->range();

        return [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'phone']),
            'customer' => $customer,
            'statement' => $customer ? $this->statement($customer) : null,
            'rangeLabel' => $this->rangeLabel(),
            'rangeFrom' => $rangeFrom,
            'rangeTo' => $rangeTo,
        ];
    }

    public function openAddEntry(): void
    {
        abort_unless($this->customer(), 404);

        $this->reset(['entryAmount', 'entryNote']);
        $this->entryKind = PartyLedgerEntry::where('customer_id', $this->customerId)->where('kind', PartyLedgerEntryKind::Opening)->exists()
            ? PartyLedgerEntryKind::Adjustment->value
            : PartyLedgerEntryKind::Opening->value;
        $this->entryDirection = PartyLedgerDirection::Debit->value;
        $this->entryDate = now()->toDateString();
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'ledger-entry-form');
    }

    public function saveEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('party-ledger'), 403);
        $customer = $this->customer();
        abort_unless($customer, 404);

        $this->validate([
            'entryKind' => ['required', Rule::enum(PartyLedgerEntryKind::class)],
            'entryDirection' => ['required', Rule::enum(PartyLedgerDirection::class)],
            'entryAmount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'entryDate' => ['required', 'date', 'before_or_equal:today'],
            'entryNote' => ['required', 'string', 'max:500'],
        ], [], [
            'entryKind' => 'type',
            'entryDirection' => 'direction',
            'entryAmount' => 'amount',
            'entryDate' => 'date',
            'entryNote' => 'note',
        ]);

        $saved = DB::transaction(function () use ($customer) {
            Customer::whereKey($customer->id)->lockForUpdate()->first();

            if ($this->entryKind === PartyLedgerEntryKind::Opening->value
                && PartyLedgerEntry::where('customer_id', $customer->id)->where('kind', PartyLedgerEntryKind::Opening)->exists()) {
                return false;
            }

            PartyLedgerEntry::create([
                'customer_id' => $customer->id,
                'user_id' => Auth::id(),
                'kind' => $this->entryKind,
                'direction' => $this->entryDirection,
                'amount' => $this->entryAmount,
                'entry_date' => $this->entryDate,
                'note' => $this->entryNote,
            ]);

            return true;
        });

        if (! $saved) {
            $this->addError('entryKind', 'This customer already has an opening balance. Cancel it first, or add an adjustment instead.');

            return;
        }

        $this->dispatch('close-modal', name: 'ledger-entry-form');
        $this->reset(['entryAmount', 'entryNote']);
        $this->toastSuccess('Entry added.');
    }

    public function closeEntryForm(): void
    {
        $this->dispatch('close-modal', name: 'ledger-entry-form');
        $this->resetErrorBag();
    }

    public function openCancelEntry(int $entryId): void
    {
        $entry = PartyLedgerEntry::where('customer_id', $this->customerId)->findOrFail($entryId);

        $this->cancelingEntryId = $entry->id;
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cancel-entry-form');
    }

    public function cancelEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('party-ledger'), 403);

        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], [], ['cancelReason' => 'reason']);

        DB::transaction(function () {
            $entry = PartyLedgerEntry::where('customer_id', $this->customerId)->lockForUpdate()->findOrFail($this->cancelingEntryId);

            $entry->update([
                'status' => RecordStatus::Cancelled,
                'cancelled_at' => now(),
                'cancelled_by' => Auth::id(),
                'cancel_reason' => $this->cancelReason,
            ]);
        });

        $this->dispatch('close-modal', name: 'cancel-entry-form');
        $this->reset(['cancelingEntryId', 'cancelReason']);
        $this->toastSuccess('Entry cancelled.');
    }

    public function closeCancelEntry(): void
    {
        $this->dispatch('close-modal', name: 'cancel-entry-form');
        $this->reset(['cancelingEntryId', 'cancelReason']);
    }

    protected function exportSections(bool $forExcel): array
    {
        $customer = $this->customer();
        $statement = $this->statement($customer);
        [$from, $to] = $this->range();

        $money = fn (?float $amount) => $forExcel
            ? $amount
            : ($amount === null ? '' : 'Rs '.number_format(abs($amount), 2).($amount < 0 ? ' (advance)' : ''));
        $date = fn (Carbon $d) => $forExcel ? TableExport::excelDate($d) : $d->format('d M Y');

        $titles = [
            'sales' => 'Sales',
            'udhaar' => 'Udhaar',
            'manual' => 'Opening & adjustments',
        ];

        return collect($titles)->map(function (string $title, string $key) use ($statement, $money, $date, $from, $to) {
            $section = $statement[$key];

            $rows = collect([[$date($from), 'Balance brought forward', $money(null), $money(null), $money($section['opening'])]])
                ->concat($section['rows']->map(fn (array $row) => [
                    $date($row['date']),
                    TableExportService::sanitizeCell($row['description'].($row['detail'] ? ' — '.$row['detail'] : '')),
                    $money($row['side'] === 'debit' ? $row['debit'] : null),
                    $money($row['side'] === 'credit' ? $row['credit'] : null),
                    $money($row['balance']),
                ]))
                ->push([$date($to), 'Closing balance', $money($section['debits']), $money($section['credits']), $money($section['closing'])]);

            return [
                'title' => $title,
                'headers' => ['Date', 'Description', 'Debit', 'Credit', 'Balance'],
                'rows' => $rows->all(),
                'columnTypes' => ['date', 'string', 'currency', 'currency', 'currency'],
            ];
        })->values()->all();
    }

    protected function exportTitle(): string
    {
        return 'Party Ledger — '.$this->customer()->name;
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        if (! $this->customer()) {
            $this->toastError('Choose a customer first.');

            return null;
        }

        return $this->guardExportSize(fn () => $exportService->toPdfSections(
            $this->exportTitle(),
            Auth::user()->shop->name,
            'Statement: '.$this->rangeLabel(),
            array_map(fn (array $s) => ['title' => $s['title'], 'headers' => $s['headers'], 'rows' => $s['rows']], $this->exportSections(forExcel: false)),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        if (! $this->customer()) {
            $this->toastError('Choose a customer first.');

            return null;
        }

        return $this->guardExportSize(fn () => $exportService->toExcelSections(
            $this->exportTitle(),
            Auth::user()->shop->name,
            'Statement: '.$this->rangeLabel(),
            $this->exportSections(forExcel: true),
        ));
    }
}; ?>

@php
    $sectionMeta = [
        'sales' => ['title' => 'Sales', 'description' => 'Sales on credit and the payments received against them.'],
        'udhaar' => ['title' => 'Udhaar', 'description' => 'Udhaar given and repaid. Recorded on the Udhaar screen.'],
        'manual' => ['title' => 'Opening & adjustments', 'description' => 'Entered by hand on this screen. Kept separate from Sales and Udhaar.'],
    ];
    $balanceText = fn (float $amount) => 'Rs '.number_format(abs($amount), 2);
@endphp

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Party Ledger</h1>
    </x-slot>

    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div class="flex w-full flex-wrap items-end gap-4 sm:w-auto">
            <x-ui.field label="Customer" name="customerId" for="customerId" class="w-full sm:w-72">
                <x-ui.select wire:model.live="customerId" id="customerId">
                    <option value="">Choose a customer…</option>
                    @foreach ($customers as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}{{ $option->phone ? ' · '.$option->phone : '' }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="From" name="from" for="from">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.export-dropdown />
            @if ($customer)
                <x-ui.button wire:click="openAddEntry">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                    </svg>
                    Add Entry
                </x-ui.button>
            @endif
        </div>
    </div>

    @if (! $customer)
        <x-ui.empty-state
            title="Choose a customer"
            description="Pick a customer above to see their statement: Sales, Udhaar, and opening balances and adjustments, each with its own running balance."
        />
    @else
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <h2 class="text-base font-semibold text-slate-900">
                <a href="{{ route('customers.show', $customer) }}" wire:navigate class="hover:text-brand-700">{{ $customer->name }}</a>
                @if ($customer->phone)
                    <span class="ml-1 text-sm font-normal text-slate-500">{{ $customer->phone }}</span>
                @endif
            </h2>
            <p class="text-sm text-slate-500">Statement {{ $rangeLabel }}</p>
        </div>

        <div class="mb-2 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <x-ui.stat label="Sales outstanding" :value="$balanceText($statement['sales']['closing'])" sub="Closing balance" />
            <x-ui.stat
                label="Udhaar balance"
                :value="$balanceText($statement['udhaar']['closing'])"
                :sub="match (true) { $statement['udhaar']['closing'] > 0 => 'Due', $statement['udhaar']['closing'] < 0 => 'Advance', default => 'Settled' }"
            />
            <x-ui.stat
                label="Opening & adjustments"
                :value="$balanceText($statement['manual']['closing'])"
                :sub="$statement['manual']['closing'] < 0 ? 'In customer\'s favour' : 'Closing balance'"
            />
        </div>
        <p class="mb-6 text-xs text-slate-500">Three separate balances. They are never added together.</p>

        <div class="space-y-6">
            @foreach ($sectionMeta as $key => $meta)
                @php $section = $statement[$key]; @endphp
                <section wire:key="section-{{ $key }}">
                    <div class="mb-2">
                        <h3 class="text-sm font-semibold text-slate-900">{{ $meta['title'] }}</h3>
                        <p class="text-xs text-slate-500">{{ $meta['description'] }}</p>
                    </div>
                    {{-- Phones: a stacked list, so the amount and running balance are visible without scrolling sideways. --}}
                    <div class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white text-sm shadow-card sm:hidden">
                        <div class="flex items-baseline justify-between gap-3 bg-slate-50/60 px-4 py-2.5">
                            <div>
                                <p class="font-medium text-slate-600">Balance brought forward</p>
                                <p class="text-xs text-slate-500">{{ $rangeFrom->format('d M Y') }}</p>
                            </div>
                            <p class="whitespace-nowrap font-medium tabular-nums text-slate-900">{{ $balanceText($section['opening']) }}{{ $section['opening'] < 0 ? ' (advance)' : '' }}</p>
                        </div>

                        @forelse ($section['rows'] as $row)
                            <div wire:key="m-{{ $row['key'] }}" class="flex items-start justify-between gap-3 px-4 py-2.5">
                                <div class="min-w-0">
                                    @if ($row['url'])
                                        <a href="{{ $row['url'] }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-700">{{ $row['description'] }}</a>
                                    @else
                                        <p class="font-medium text-slate-800">{{ $row['description'] }}</p>
                                    @endif
                                    <p class="text-xs text-slate-500">{{ $row['date']->format('d M Y') }}@if ($row['detail']) · {{ $row['detail'] }}@endif</p>
                                    @if ($key === 'manual')
                                        <button type="button" wire:click="openCancelEntry({{ $row['entry']->id }})" class="mt-1 text-xs font-medium text-red-600 hover:text-red-700">Cancel</button>
                                    @endif
                                </div>
                                <div class="shrink-0 text-right tabular-nums">
                                    <p @class(['whitespace-nowrap font-medium', 'text-red-600' => $row['side'] === 'debit', 'text-emerald-600' => $row['side'] === 'credit'])>
                                        {{ $row['side'] === 'debit' ? '+' : '−' }} Rs {{ number_format($row['side'] === 'debit' ? $row['debit'] : $row['credit'], 2) }}
                                    </p>
                                    <p class="whitespace-nowrap text-xs text-slate-500">Bal {{ $balanceText($row['balance']) }}{{ $row['balance'] < 0 ? ' (adv.)' : '' }}</p>
                                </div>
                            </div>
                        @empty
                            <p class="px-4 py-3 text-center text-slate-400">No entries in this period</p>
                        @endforelse

                        <div class="flex items-baseline justify-between gap-3 bg-slate-50 px-4 py-2.5">
                            <div>
                                <p class="font-semibold text-slate-900">Closing balance</p>
                                <p class="text-xs text-slate-500">{{ $rangeTo->format('d M Y') }}</p>
                            </div>
                            <p class="whitespace-nowrap font-semibold tabular-nums text-slate-900">{{ $balanceText($section['closing']) }}{{ $section['closing'] < 0 ? ' (advance)' : '' }}</p>
                        </div>
                    </div>

                    <x-ui.table class="hidden sm:block" :headers="$key === 'manual' ? ['Date', 'Description', 'Debit', 'Credit', 'Balance', ''] : ['Date', 'Description', 'Debit', 'Credit', 'Balance']">
                        <x-ui.table-row class="bg-slate-50/60">
                            <x-ui.table-cell class="text-slate-500">{{ $rangeFrom->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-600">Balance brought forward</x-ui.table-cell>
                            <x-ui.table-cell></x-ui.table-cell>
                            <x-ui.table-cell></x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-900">
                                {{ $balanceText($section['opening']) }}{{ $section['opening'] < 0 ? ' (advance)' : '' }}
                            </x-ui.table-cell>
                            @if ($key === 'manual')
                                <x-ui.table-cell></x-ui.table-cell>
                            @endif
                        </x-ui.table-row>

                        @forelse ($section['rows'] as $row)
                            <x-ui.table-row wire:key="{{ $row['key'] }}">
                                <x-ui.table-cell>{{ $row['date']->format('d M Y') }}</x-ui.table-cell>
                                <x-ui.table-cell class="whitespace-normal">
                                    <div class="flex min-w-[12rem] flex-col">
                                        @if ($row['url'])
                                            <a href="{{ $row['url'] }}" wire:navigate class="font-medium text-slate-800 hover:text-brand-700">{{ $row['description'] }}</a>
                                        @else
                                            <span class="font-medium text-slate-800">{{ $row['description'] }}</span>
                                        @endif
                                        @if ($row['detail'])
                                            <span class="text-xs text-slate-500">{{ $row['detail'] }}</span>
                                        @endif
                                    </div>
                                </x-ui.table-cell>
                                <x-ui.table-cell>{{ $row['side'] === 'debit' ? 'Rs '.number_format($row['debit'], 2) : '' }}</x-ui.table-cell>
                                <x-ui.table-cell>{{ $row['side'] === 'credit' ? 'Rs '.number_format($row['credit'], 2) : '' }}</x-ui.table-cell>
                                <x-ui.table-cell class="font-medium text-slate-900">
                                    {{ $balanceText($row['balance']) }}{{ $row['balance'] < 0 ? ' (advance)' : '' }}
                                </x-ui.table-cell>
                                @if ($key === 'manual')
                                    <x-ui.table-cell align="right">
                                        <x-ui.button size="sm" variant="ghost" wire:click="openCancelEntry({{ $row['entry']->id }})" class="text-red-600 hover:bg-red-50">
                                            Cancel
                                        </x-ui.button>
                                    </x-ui.table-cell>
                                @endif
                            </x-ui.table-row>
                        @empty
                            <x-ui.table-row>
                                <x-ui.table-cell :colspan="$key === 'manual' ? 6 : 5" class="py-4 text-center text-slate-400">No entries in this period</x-ui.table-cell>
                            </x-ui.table-row>
                        @endforelse

                        <x-ui.table-row class="bg-slate-50">
                            <x-ui.table-cell class="text-slate-500">{{ $rangeTo->format('d M Y') }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">Closing balance</x-ui.table-cell>
                            <x-ui.table-cell class="text-slate-600">{{ $section['debits'] ? 'Rs '.number_format($section['debits'], 2) : '' }}</x-ui.table-cell>
                            <x-ui.table-cell class="text-slate-600">{{ $section['credits'] ? 'Rs '.number_format($section['credits'], 2) : '' }}</x-ui.table-cell>
                            <x-ui.table-cell class="font-semibold text-slate-900">
                                {{ $balanceText($section['closing']) }}{{ $section['closing'] < 0 ? ' (advance)' : '' }}
                            </x-ui.table-cell>
                            @if ($key === 'manual')
                                <x-ui.table-cell></x-ui.table-cell>
                            @endif
                        </x-ui.table-row>
                    </x-ui.table>
                </section>
            @endforeach
        </div>
    @endif

    <x-ui.modal name="ledger-entry-form" max-width="sm">
        <form wire:submit="saveEntry" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Add Entry</h2>
            <p class="mt-1 text-sm text-slate-500">
                Counts only toward this customer's "Opening & adjustments" balance, never Sales or Udhaar. To record udhaar, use the Udhaar screen.
            </p>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Type" name="entryKind" for="entryKind">
                    <x-ui.select wire:model="entryKind" id="entryKind">
                        @foreach (\App\Enums\PartyLedgerEntryKind::cases() as $kind)
                            <option value="{{ $kind->value }}">{{ $kind->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Direction" name="entryDirection" for="entryDirection">
                    <x-ui.select wire:model="entryDirection" id="entryDirection">
                        @foreach (\App\Enums\PartyLedgerDirection::cases() as $direction)
                            <option value="{{ $direction->value }}">{{ $direction->label() }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>

                <x-ui.field label="Amount" name="entryAmount" for="entryAmount">
                    <x-ui.input wire:model="entryAmount" id="entryAmount" type="number" min="0.01" step="0.01" class="text-lg" />
                </x-ui.field>

                <x-ui.field label="Date" name="entryDate" for="entryDate">
                    <x-ui.input wire:model="entryDate" id="entryDate" type="date" />
                </x-ui.field>

                <x-ui.field label="Note" name="entryNote" for="entryNote" help="Required. Why this entry is being made.">
                    <x-ui.input wire:model="entryNote" id="entryNote" maxlength="500" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEntryForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEntry">
                    Save Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-entry-form" max-width="sm">
        <form wire:submit="cancelEntry" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Entry?</h2>
            <p class="mt-2 text-sm text-slate-600">
                The entry will be marked <strong>Cancelled</strong> and stop counting toward the balance. It is kept on record. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" maxlength="500" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelEntry">
                    Keep Entry
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelEntry" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
