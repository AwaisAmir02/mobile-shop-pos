<?php

use App\Models\Customer;
use App\Models\StoreCreditEntry;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Store Credit')] class extends Component
{
    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(): void
    {
        $this->from = $this->from ?: now()->startOfMonth()->toDateString();
        $this->to = $this->to ?: BatchExpiry::today();
    }

    public function with(): array
    {
        $today = BatchExpiry::today();

        // Movement in the period, by kind.
        $period = StoreCreditEntry::query()
            ->when($this->from, fn ($q) => $q->where('entry_date', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->where('entry_date', '<=', $this->to))
            ->groupBy('type')
            ->selectRaw('type, SUM(amount) as total')
            ->pluck('total', 'type')
            ->map(fn ($total) => abs((float) $total));

        // Who holds credit today: everything still active, by customer.
        $holders = StoreCreditEntry::query()
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as balance')
            ->havingRaw('SUM(amount) <> 0')
            ->pluck('balance', 'customer_id');

        $lots = StoreCreditEntry::query()
            ->where('type', StoreCreditEntry::ISSUE)
            ->where('remaining', '>', 0)
            ->whereIn('customer_id', $holders->keys())
            ->get(['customer_id', 'remaining', 'expires_on'])
            ->groupBy('customer_id');

        $customers = Customer::query()->whereIn('id', $holders->keys())->get(['id', 'name', 'phone'])->keyBy('id');

        $rows = $holders->map(function ($balance, $customerId) use ($lots, $customers, $today) {
            $mine = $lots->get($customerId, collect());

            return [
                'customer' => $customers->get($customerId),
                'balance' => round((float) $balance, 2),
                'spendable' => round((float) $mine->filter(fn ($lot) => $lot->expires_on === null || $lot->expires_on->toDateString() >= $today)->sum('remaining'), 2),
                'nextExpiry' => $mine->filter(fn ($lot) => $lot->expires_on !== null && $lot->expires_on->toDateString() >= $today)->min('expires_on'),
            ];
        })->filter(fn (array $row) => $row['customer'] !== null)->sortByDesc('balance')->values();

        return [
            'held' => round($rows->sum('balance'), 2),
            'issued' => (float) ($period['issue'] ?? 0),
            'spent' => (float) ($period['spend'] ?? 0),
            'paidOut' => (float) ($period['cash_out'] ?? 0),
            'expired' => (float) ($period['expire'] ?? 0),
            'rows' => $rows,
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('reports.index') }}" back-label="Reports" subtitle="Credit given on returns, what's been used and who still holds it">Store Credit</x-ui.page-header>
    </x-slot>

    <x-ui.filter-bar :active="0">
        <x-ui.field label="From" name="from" for="from" class="sm:max-w-[12rem]">
            <x-ui.input wire:model.live="from" id="from" type="date" />
        </x-ui.field>
        <x-ui.field label="To" name="to" for="to" class="sm:max-w-[12rem]">
            <x-ui.input wire:model.live="to" id="to" type="date" />
        </x-ui.field>
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Held by customers" value="Rs {{ number_format($held, 2) }}" sub="What the shop owes in credit today" />
        <x-ui.stat label="Given" value="Rs {{ number_format($issued, 2) }}" sub="On returns, in this period" />
        <x-ui.stat label="Spent" value="Rs {{ number_format($spent, 2) }}" sub="On sales, in this period" />
        <x-ui.stat label="Paid out / expired" value="Rs {{ number_format($paidOut + $expired, 2) }}" :sub="'Rs '.number_format($paidOut, 2).' paid out · Rs '.number_format($expired, 2).' expired'" />
    </x-ui.stat-row>

    <x-ui.card :padding="false" title="Customers holding credit">
        @if ($rows->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-ink-faint">No customer holds store credit right now.</p>
        @else
            <ul class="divide-y divide-line">
                @foreach ($rows as $row)
                    <li wire:key="holder-{{ $row['customer']->id }}" class="flex items-center justify-between gap-4 px-4 py-3 sm:px-5">
                        <div class="min-w-0">
                            <a href="{{ route('customers.show', $row['customer']) }}" wire:navigate class="text-sm font-semibold text-link hover:underline">{{ $row['customer']->name }}</a>
                            <p class="text-xs text-ink-faint">
                                {{ $row['customer']->phone }}
                                @if ($row['nextExpiry'])
                                    · next expiry {{ $row['nextExpiry']->format('d M Y') }}
                                @endif
                                @if ($row['spendable'] < $row['balance'])
                                    · Rs {{ number_format($row['balance'] - $row['spendable'], 2) }} past expiry
                                @endif
                            </p>
                        </div>
                        <span class="whitespace-nowrap text-sm font-bold tabular-nums text-ink">Rs {{ number_format($row['balance'], 2) }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-ui.card>
</div>
