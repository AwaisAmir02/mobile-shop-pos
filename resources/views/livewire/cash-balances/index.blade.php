<?php

use App\Actions\MoneyEntries;
use App\Livewire\Concerns\Toasts;
use App\Models\MoneyEntry;
use App\Support\MoneyFlow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cash & Balances')] class extends Component
{
    use Toasts;

    #[Url]
    public string $tab = 'balances';

    // Daily flow
    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    // Opening balance / count form
    public string $formBucket = '';
    public string $openingAmount = '';
    public string $countedAmount = '';
    public string $entryDate = '';
    public string $entryNote = '';

    // Move money form
    public string $moveFrom = '';
    public string $moveTo = '';
    public string $moveAmount = '';

    public function mount(): void
    {
        if (! in_array($this->tab, ['balances', 'flow', 'entries'], true)) {
            $this->tab = 'balances';
        }

        if ($this->from === '' && $this->to === '') {
            $this->from = $this->to = MoneyFlow::today();
        }
    }

    protected function flow(): MoneyFlow
    {
        return new MoneyFlow((int) Auth::user()->shop_id);
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['balances', 'flow', 'entries'], true)) {
            $this->tab = $tab;
        }
    }

    protected function checkBucket(string $bucket, string $field): void
    {
        if (! array_key_exists($bucket, $this->flow()->buckets())) {
            throw ValidationException::withMessages([$field => 'Pick cash or one of your accounts.']);
        }
    }

    public function openOpening(string $bucket): void
    {
        $this->resetErrorBag();
        $this->formBucket = $bucket;
        $this->openingAmount = '';
        $this->entryDate = MoneyFlow::today();
        $this->entryNote = '';
        $this->dispatch('open-modal', name: 'opening-form');
    }

    public function saveOpening(): void
    {
        $this->validate([
            'openingAmount' => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'entryDate' => ['required', 'date'],
            'entryNote' => ['nullable', 'string', 'max:255'],
        ]);
        $this->checkBucket($this->formBucket, 'openingAmount');

        MoneyEntries::opening((int) Auth::user()->shop_id, $this->formBucket, (float) $this->openingAmount, $this->entryDate, Auth::id(), $this->entryNote ?: null);

        $this->dispatch('close-modal', name: 'opening-form');
        $this->toastSuccess('Opening balance saved.');
    }

    public function openCount(string $bucket): void
    {
        $this->resetErrorBag();
        $this->formBucket = $bucket;
        $this->countedAmount = '';
        $this->entryDate = MoneyFlow::today();
        $this->entryNote = '';
        $this->dispatch('open-modal', name: 'count-form');
    }

    public function saveCount(): void
    {
        $this->validate([
            'countedAmount' => ['required', 'numeric', 'min:-99999999', 'max:99999999'],
            'entryDate' => ['required', 'date'],
            'entryNote' => ['nullable', 'string', 'max:255'],
        ]);
        $this->checkBucket($this->formBucket, 'countedAmount');

        $entry = MoneyEntries::count((int) Auth::user()->shop_id, $this->formBucket, (float) $this->countedAmount, $this->entryDate, Auth::id(), $this->entryNote ?: null);

        $this->dispatch('close-modal', name: 'count-form');
        $difference = (float) $entry->amount;
        $this->toastSuccess(abs($difference) < 0.005
            ? 'Count saved. It matches.'
            : 'Count saved. Rs '.number_format(abs($difference), 2).($difference > 0 ? ' more' : ' less').' than expected; the balance now matches your count.');
    }

    public function openMove(): void
    {
        $this->resetErrorBag();
        $this->moveFrom = '';
        $this->moveTo = '';
        $this->moveAmount = '';
        $this->entryDate = MoneyFlow::today();
        $this->entryNote = '';
        $this->dispatch('open-modal', name: 'move-form');
    }

    public function saveMove(): void
    {
        $this->validate([
            'moveFrom' => ['required', 'string'],
            'moveTo' => ['required', 'string'],
            'moveAmount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'entryDate' => ['required', 'date'],
            'entryNote' => ['nullable', 'string', 'max:255'],
        ]);
        $this->checkBucket($this->moveFrom, 'moveFrom');
        $this->checkBucket($this->moveTo, 'moveTo');

        MoneyEntries::transfer((int) Auth::user()->shop_id, $this->moveFrom, $this->moveTo, (float) $this->moveAmount, $this->entryDate, Auth::id(), $this->entryNote ?: null);

        $this->dispatch('close-modal', name: 'move-form');
        $this->toastSuccess('Money moved.');
    }

    public function cancelEntry(int $id): void
    {
        $entry = MoneyEntry::findOrFail($id);
        MoneyEntries::cancel($entry, Auth::id());
        $this->toastSuccess($entry->kindLabel().' cancelled.');
    }

    /** The expected balance shown on the count form, for the chosen day. */
    public function expectedForCount(): ?float
    {
        if ($this->formBucket === '' || $this->entryDate === '' || ! array_key_exists($this->formBucket, $this->flow()->buckets())) {
            return null;
        }

        return $this->flow()->balanceOf($this->formBucket, min($this->entryDate, MoneyFlow::today()));
    }

    public function with(): array
    {
        $flow = $this->flow();
        $buckets = $flow->buckets();
        $data = ['buckets' => $buckets];

        if ($this->tab === 'balances') {
            $since = CarbonImmutable::parse(MoneyFlow::today())->subDays(29)->toDateString();
            $unrecorded = $flow->movements($since, MoneyFlow::today())->where('bucket', 'unrecorded');

            $data += [
                'balances' => $flow->balances(),
                'unrecordedIn' => round($unrecorded->where('amount', '>', 0)->sum('amount'), 2),
                'unrecordedOut' => round(-$unrecorded->where('amount', '<', 0)->sum('amount'), 2),
            ];
        }

        if ($this->tab === 'flow') {
            $to = $this->to !== '' ? $this->to : MoneyFlow::today();
            // "Any time" would be every day the shop has existed; the last 90 days is plenty to read.
            $from = $this->from !== '' ? $this->from : CarbonImmutable::parse($to)->subDays(89)->toDateString();
            [$from, $to] = $from > $to ? [$to, $from] : [$from, $to];
            $days = $flow->daily($from, $to);
            $all = $days->flatMap(fn ($day) => $day['rows']);
            $isCash = fn ($row) => str_starts_with($row['bucket'], 'cash:');
            $isAccount = fn ($row) => str_starts_with($row['bucket'], 'account:');
            $realMoney = fn ($row) => $row['source'] !== 'entry';

            $data += [
                'days' => $days,
                'rangeFrom' => $from,
                'rangeTo' => $to,
                'totals' => [
                    'cashIn' => round($all->filter($isCash)->filter($realMoney)->where('amount', '>', 0)->sum('amount'), 2),
                    'cashOut' => round(-$all->filter($isCash)->filter($realMoney)->where('amount', '<', 0)->sum('amount'), 2),
                    'accountIn' => round($all->filter($isAccount)->filter($realMoney)->where('amount', '>', 0)->sum('amount'), 2),
                    'accountOut' => round(-$all->filter($isAccount)->filter($realMoney)->where('amount', '<', 0)->sum('amount'), 2),
                    'fees' => round($days->sum('fees'), 2),
                ],
            ];
        }

        if ($this->tab === 'entries') {
            $data['entries'] = MoneyEntry::withoutGlobalScope(\App\Models\Scopes\ExcludesCancelledScope::class)
                ->with(['user', 'fromAccount', 'toAccount', 'branch'])
                ->latest('entry_date')->latest('id')->limit(200)->get();
        }

        return $data;
    }
}; ?>

@php
    $money = fn ($value) => 'Rs '.number_format((float) $value, 2);
    $signed = fn ($value) => ((float) $value < 0 ? '− ' : '').'Rs '.number_format(abs((float) $value), 2);
    $bucketLabel = fn ($key) => $key === 'unrecorded' ? 'Not recorded' : ($buckets[$key]['label'] ?? 'Removed account');
    $sourceLabels = \App\Support\MoneyFlow::SOURCES;
@endphp

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Cash in hand and each of your accounts: what's there, and what came in and went out each day">Cash &amp; Balances</x-ui.page-header>
    </x-slot>

    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex max-w-full gap-1 overflow-x-auto rounded-full border border-line bg-surface p-1 shadow-soft" aria-label="Cash & Balances sections">
            @foreach (['balances' => 'Balances', 'flow' => 'Daily flow', 'entries' => 'Moves & counts'] as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    data-cash-tab="{{ $key }}"
                    @if ($tab === $key) aria-current="page" @endif
                    @class([
                        'shrink-0 whitespace-nowrap rounded-full px-3.5 py-1.5 text-sm font-semibold transition-colors',
                        'bg-primary-soft text-on-primary-soft' => $tab === $key,
                        'text-ink-faint hover:text-ink' => $tab !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </nav>

        <x-ui.button wire:click="openMove" size="sm" variant="secondary" data-open-move>Move money</x-ui.button>
    </div>

    @if ($tab === 'balances')
        @if ($balances->every(fn ($bucket) => $bucket['opening'] === null))
            <x-ui.note class="mb-5">
                Start by setting an opening balance for your cash and each account: what was there on the day you start. Until then, each balance simply adds up everything recorded so far, which may not match what's really there.
            </x-ui.note>
        @endif

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($balances as $bucket)
                <x-ui.card :padding="false" data-balance-card="{{ $bucket['key'] }}">
                    <div class="p-5">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-ink">{{ $bucket['label'] }}</p>
                                <p class="truncate text-xs text-ink-faint">{{ $bucket['sub'] }}</p>
                            </div>
                            <x-ui.badge :variant="$bucket['kind'] === 'cash' ? 'success' : 'brand'">{{ $bucket['kind'] === 'cash' ? 'Cash' : 'Account' }}</x-ui.badge>
                        </div>
                        <p @class(['mt-3 text-2xl font-extrabold tabular-nums tracking-tight', 'text-danger' => $bucket['balance'] < 0, 'text-ink' => $bucket['balance'] >= 0]) data-balance-value>{{ $signed($bucket['balance']) }}</p>
                        <p class="mt-1 text-xs text-ink-faint">
                            In {{ $money($bucket['in']) }} · Out {{ $money($bucket['out']) }} {{ $bucket['opening'] ? 'since '.$bucket['opening']->entry_date->format('d M Y') : 'in total' }}
                        </p>
                        <dl class="mt-3 space-y-1 border-t border-line pt-3 text-xs">
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-faint">Opening</dt>
                                <dd class="text-right font-semibold text-ink">{{ $bucket['opening'] ? $signed($bucket['opening']->amount).' on '.$bucket['opening']->entry_date->format('d M Y') : 'Not set' }}</dd>
                            </div>
                            <div class="flex justify-between gap-3">
                                <dt class="text-ink-faint">Last count</dt>
                                <dd class="text-right font-semibold text-ink">
                                    @if ($count = $bucket['last_count'])
                                        {{ $signed($count->counted) }} on {{ $count->entry_date->format('d M') }}
                                        @if (abs((float) $count->amount) >= 0.005)
                                            <span class="block font-normal {{ (float) $count->amount < 0 ? 'text-danger' : 'text-success' }}">{{ $money(abs((float) $count->amount)) }} {{ (float) $count->amount < 0 ? 'short' : 'over' }}</span>
                                        @else
                                            <span class="block font-normal text-success">Matched</span>
                                        @endif
                                    @else
                                        Never
                                    @endif
                                </dd>
                            </div>
                        </dl>
                    </div>
                    <div class="flex gap-2 border-t border-line px-5 py-3">
                        @unless ($bucket['opening'])
                            <x-ui.button size="sm" variant="secondary" wire:click="openOpening('{{ $bucket['key'] }}')" data-open-opening="{{ $bucket['key'] }}">Set opening balance</x-ui.button>
                        @endunless
                        <x-ui.button size="sm" variant="ghost" wire:click="openCount('{{ $bucket['key'] }}')" data-open-count="{{ $bucket['key'] }}">Count</x-ui.button>
                    </div>
                </x-ui.card>
            @endforeach
        </div>

        @if ($unrecordedIn > 0 || $unrecordedOut > 0)
            <x-ui.card class="mt-5" title="Not marked cash or account" description="Last 30 days. This money moved, but nobody picked Cash or an account, so it isn't in any balance above." data-unrecorded>
                <p class="text-sm text-ink-muted">In {{ $money($unrecordedIn) }} · Out {{ $money($unrecordedOut) }}. Most of it is usually Mark as Paid on a counter service, or older entries. Choosing Cash or an account when you record a payment keeps the balances right.</p>
            </x-ui.card>
        @endif
    @endif

    @if ($tab === 'flow')
        <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to)">
            <x-ui.date-range />
        </x-ui.filter-bar>

        <div class="mb-5 grid grid-cols-2 gap-4 lg:grid-cols-5" data-flow-totals>
            <x-ui.stat label="Cash came in" :value="$money($totals['cashIn'])" />
            <x-ui.stat label="Cash went out" :value="$money($totals['cashOut'])" />
            <x-ui.stat label="Into accounts" :value="$money($totals['accountIn'])" />
            <x-ui.stat label="Out of accounts" :value="$money($totals['accountOut'])" />
            <x-ui.stat label="Fees earned" :value="$money($totals['fees'])" sub="Wallet, bills, balance, SIM, NADRA" />
        </div>

        @forelse ($days as $day)
            <x-ui.card class="mb-4" :padding="false" data-flow-day="{{ $day['date'] }}">
                <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-line px-5 py-3">
                    <h2 class="text-sm font-bold text-ink">{{ \Carbon\Carbon::parse($day['date'])->format('l, d M Y') }}</h2>
                    <p class="text-xs text-ink-faint">Fees earned <span class="font-semibold text-ink">{{ $money($day['fees']) }}</span></p>
                </div>
                @if ($day['buckets']->isEmpty())
                    <p class="px-5 py-4 text-sm text-ink-faint">No money moved through cash or accounts.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs font-semibold uppercase tracking-[0.06em] text-ink-faint">
                                    <th class="px-5 py-2">Cash or account</th>
                                    <th class="hidden px-3 py-2 text-right sm:table-cell">In</th>
                                    <th class="hidden px-3 py-2 text-right sm:table-cell">Out</th>
                                    <th class="px-5 py-2 text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-line">
                                @foreach ($day['buckets']->sortBy(fn ($row) => ($row['bucket'] === 'unrecorded' ? '2' : (str_starts_with($row['bucket'], 'cash:') ? '0' : '1')).$bucketLabel($row['bucket'])) as $row)
                                    <tr x-data="{ open: false }" data-flow-row="{{ $row['bucket'] }}" @class(['text-ink-faint' => $row['bucket'] === 'unrecorded'])>
                                        <td class="px-5 py-2.5">
                                            <button type="button" x-on:click="open = ! open" :aria-expanded="open.toString()" class="text-left font-semibold text-ink hover:text-link">{{ $bucketLabel($row['bucket']) }}</button>
                                            <p class="text-xs tabular-nums sm:hidden"><span class="text-success">In {{ $money($row['in']) }}</span> · <span class="text-danger">Out {{ $money($row['out']) }}</span></p>
                                            <div x-show="open" x-cloak class="mt-1.5 space-y-0.5 text-xs text-ink-faint">
                                                @foreach ($row['sources'] as $source => $amounts)
                                                    <p>{{ $sourceLabels[$source] ?? $source }}: @if ($amounts['in'] > 0) in {{ $money($amounts['in']) }} @endif @if ($amounts['out'] > 0) out {{ $money($amounts['out']) }} @endif</p>
                                                @endforeach
                                            </div>
                                        </td>
                                        <td class="hidden whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-success sm:table-cell">{{ $row['in'] > 0 ? $money($row['in']) : '—' }}</td>
                                        <td class="hidden whitespace-nowrap px-3 py-2.5 text-right tabular-nums text-danger sm:table-cell">{{ $row['out'] > 0 ? $money($row['out']) : '—' }}</td>
                                        <td class="whitespace-nowrap px-5 py-2.5 text-right align-top font-semibold tabular-nums text-ink">{{ $signed($row['net']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <details class="border-t border-line px-5 py-3 text-sm">
                        <summary class="cursor-pointer text-xs font-semibold text-link">Every entry that day ({{ $day['rows']->count() }})</summary>
                        <ul class="mt-2 divide-y divide-line">
                            @foreach ($day['rows'] as $line)
                                <li class="flex items-center justify-between gap-3 py-1.5 text-xs">
                                    <span class="min-w-0">
                                        @if ($line['link'] && \Illuminate\Support\Facades\Route::has($line['link'][0]))
                                            <a href="{{ route($line['link'][0], $line['link'][1] ?? []) }}" wire:navigate class="font-medium text-ink hover:text-link">{{ $line['label'] }}</a>
                                        @else
                                            <span class="font-medium text-ink">{{ $line['label'] }}</span>
                                        @endif
                                        <span class="text-ink-faint"> · {{ $bucketLabel($line['bucket']) }}</span>
                                    </span>
                                    <span @class(['shrink-0 font-semibold tabular-nums', 'text-success' => $line['amount'] > 0, 'text-danger' => $line['amount'] < 0])>{{ $line['amount'] > 0 ? '+' : '−' }} {{ $money(abs($line['amount'])) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </x-ui.card>
        @empty
            <x-ui.empty-state title="Nothing in these dates" description="No money moved through cash or accounts on these days." />
        @endforelse
    @endif

    @if ($tab === 'entries')
        @if ($entries->isEmpty())
            <x-ui.empty-state title="No openings, moves or counts yet" description="Set opening balances on the Balances tab, use Move money when cash goes into an account (or the other way), and Count to check a balance." />
        @else
            <x-ui.table stack :headers="['Date', 'What', 'Where', 'Amount', 'By', '']">
                @foreach ($entries as $entry)
                    @php
                        $fromKey = $entry->fromBucket();
                        $toKey = $entry->toBucket();
                    @endphp
                    <x-ui.table-row wire:key="money-entry-{{ $entry->id }}" @class(['opacity-50' => $entry->isCancelled()])>
                        <x-ui.table-cell primary>{{ $entry->entry_date->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell label="What">
                            {{ $entry->kindLabel() }}
                            @if ($entry->isCancelled())
                                <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                            @endif
                            @if ($entry->note)
                                <span class="ui-sub">{{ $entry->note }}</span>
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Where">{{ $fromKey ? $bucketLabel($fromKey).' → ' : '' }}{{ $toKey ? $bucketLabel($toKey) : '' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Amount" class="font-semibold text-ink">
                            @if ($entry->kind === \App\Models\MoneyEntry::COUNT)
                                Counted {{ $signed($entry->counted) }}
                                <span class="ui-sub">Expected {{ $signed($entry->expected) }} · {{ abs((float) $entry->amount) < 0.005 ? 'matched' : $money(abs((float) $entry->amount)).((float) $entry->amount < 0 ? ' short' : ' over') }}</span>
                            @else
                                {{ $signed($entry->amount) }}
                            @endif
                        </x-ui.table-cell>
                        <x-ui.table-cell label="By">{{ $entry->user?->name ?? '—' }}</x-ui.table-cell>
                        <x-ui.table-cell actions align="right">
                            @unless ($entry->isCancelled())
                                <x-ui.button size="sm" variant="ghost" wire:click="cancelEntry({{ $entry->id }})" confirm="Cancel this {{ strtolower($entry->kindLabel()) }}? It stops counting in the balances." confirm-title="Cancel entry?" confirm-label="Cancel entry">Cancel</x-ui.button>
                            @endunless
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @endif

    <x-ui.modal name="opening-form" max-width="sm">
        <form wire:submit="saveOpening" class="p-6">
            <h2 class="text-lg font-bold text-ink">Opening balance</h2>
            <p class="mt-1 text-sm text-ink-muted">{{ $formBucket !== '' ? $bucketLabel($formBucket) : '' }}: what was there at the start of the day you pick. Everything recorded from that day on is added to it.</p>
            <div class="mt-5 space-y-4">
                <x-ui.field label="Amount" name="openingAmount" for="openingAmount" required>
                    <x-ui.input wire:model="openingAmount" id="openingAmount" type="number" step="0.01" />
                </x-ui.field>
                <x-ui.field label="On" name="entryDate" for="openingDate" required>
                    <x-ui.input wire:model="entryDate" id="openingDate" type="date" max="{{ \App\Support\MoneyFlow::today() }}" />
                </x-ui.field>
                <x-ui.field label="Note" name="entryNote" for="openingNote" help="Optional">
                    <x-ui.input wire:model="entryNote" id="openingNote" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'opening-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveOpening">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="count-form" max-width="sm">
        <form wire:submit="saveCount" class="p-6">
            <h2 class="text-lg font-bold text-ink">Count {{ $formBucket !== '' ? $bucketLabel($formBucket) : '' }}</h2>
            <p class="mt-1 text-sm text-ink-muted">Count the cash, or check the balance in the JazzCash, Easypaisa or bank app, and enter it here.</p>
            <div class="mt-5 space-y-4">
                <x-ui.field label="On" name="entryDate" for="countDate" required>
                    <x-ui.input wire:model.live="entryDate" id="countDate" type="date" max="{{ \App\Support\MoneyFlow::today() }}" />
                </x-ui.field>
                @php($expected = $this->expectedForCount())
                @if ($expected !== null)
                    <p class="rounded-xl bg-surface-sunken/60 px-3 py-2 text-sm text-ink-muted" data-count-expected>Expected at the end of that day: <span class="font-semibold text-ink">{{ $signed($expected) }}</span></p>
                @endif
                <x-ui.field label="Counted balance" name="countedAmount" for="countedAmount" required>
                    <x-ui.input wire:model="countedAmount" id="countedAmount" type="number" step="0.01" />
                </x-ui.field>
                <x-ui.field label="Note" name="entryNote" for="countNote" help="Optional, e.g. why it's short">
                    <x-ui.input wire:model="entryNote" id="countNote" />
                </x-ui.field>
            </div>
            <p class="mt-3 text-xs text-ink-faint">Any difference is kept as a correction, so the balance matches your count from then on.</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'count-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveCount">Save count</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="move-form" max-width="sm">
        <form wire:submit="saveMove" class="p-6">
            <h2 class="text-lg font-bold text-ink">Move money</h2>
            <p class="mt-1 text-sm text-ink-muted">Cash put into the bank, JazzCash topped up from the bank, cash taken out of an account: anything that moves money between your own cash and accounts.</p>
            <div class="mt-5 space-y-4">
                <x-ui.field label="From" name="moveFrom" for="moveFrom" required>
                    <x-ui.select wire:model="moveFrom" id="moveFrom">
                        <option value="">Choose…</option>
                        @foreach ($buckets as $key => $bucket)
                            <option value="{{ $key }}">{{ $bucket['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.field label="To" name="moveTo" for="moveTo" required>
                    <x-ui.select wire:model="moveTo" id="moveTo">
                        <option value="">Choose…</option>
                        @foreach ($buckets as $key => $bucket)
                            <option value="{{ $key }}">{{ $bucket['label'] }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <div class="grid grid-cols-2 gap-4">
                    <x-ui.field label="Amount" name="moveAmount" for="moveAmount" required>
                        <x-ui.input wire:model="moveAmount" id="moveAmount" type="number" min="0.01" step="0.01" />
                    </x-ui.field>
                    <x-ui.field label="On" name="entryDate" for="moveDate" required>
                        <x-ui.input wire:model="entryDate" id="moveDate" type="date" max="{{ \App\Support\MoneyFlow::today() }}" />
                    </x-ui.field>
                </div>
                <x-ui.field label="Note" name="entryNote" for="moveNote" help="Optional">
                    <x-ui.input wire:model="entryNote" id="moveNote" />
                </x-ui.field>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" x-on:click="$dispatch('close-modal', { name: 'move-form' })">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveMove">Move</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
