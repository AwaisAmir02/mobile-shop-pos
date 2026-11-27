<?php

use App\Enums\RecordStatus;
use App\Models\CourierSettlement;
use App\ReportQueries\CourierBalances;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Courier payouts (O4): the cash on delivery each courier holds for the shop,
 * and every payout recorded against it (App\Actions\CourierSettlements).
 * Routed behind shop.tool:settlements (OnlineTool::CourierSettlements).
 */
new #[Layout('layouts.app')] #[Title('Courier Payouts')] class extends Component
{
    use WithPagination;

    public function with(): array
    {
        $balances = (new CourierBalances)->rows();

        return [
            'balances' => $balances,
            'onTheWay' => $balances->sum('on_the_way'),
            'awaiting' => $balances->sum('awaiting_payout'),
            'settlements' => CourierSettlement::query()->with('shopAccount')->latest('settlement_date')->latest('id')->paginate(20),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Cash on delivery your couriers hold for you, and what they've paid out">Courier Payouts</x-ui.page-header>
    </x-slot>

    <x-ui.stat-row>
        <x-ui.stat label="Delivered, Not Paid Yet" value="Rs {{ number_format($awaiting, 2) }}" sub="Owed by your couriers, not your customers" />
        <x-ui.stat label="On the Way" value="Rs {{ number_format($onTheWay, 2) }}" sub="Cash to collect on parcels out now" />
    </x-ui.stat-row>

    @if ($balances->isEmpty())
        <x-ui.empty-state title="No cash with couriers" description="Cash-on-delivery parcels appear here once they're booked, and wait here after delivery until the courier pays you." />
    @else
        <x-ui.table stack :headers="['Courier', 'Delivered, not paid', 'On the way', 'Came back', '']" data-courier-balances>
            @foreach ($balances as $row)
                <x-ui.table-row wire:key="balance-{{ $row['courier']->value }}-{{ $row['courier_name'] }}">
                    <x-ui.table-cell primary><span class="font-semibold text-ink">{{ $row['name'] }}</span></x-ui.table-cell>
                    <x-ui.table-cell label="Delivered, not paid" class="tabular-nums">Rs {{ number_format($row['awaiting_payout'], 2) }} <span class="text-xs text-ink-faint">· {{ $row['delivered'] }} {{ \Illuminate\Support\Str::plural('parcel', $row['delivered']) }}</span></x-ui.table-cell>
                    <x-ui.table-cell label="On the way" class="tabular-nums">Rs {{ number_format($row['on_the_way'], 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Came back" class="tabular-nums">{{ $row['returned'] }}{{ $row['return_charges'] > 0 ? ' · Rs '.number_format($row['return_charges'], 2).' to return' : '' }}</x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        @if ($row['delivered'] + $row['returned'] > 0)
                            <x-ui.button size="sm" href="{{ route('courier-settlements.create', ['courier' => $row['courier']->value, 'name' => $row['courier_name']]) }}" wire:navigate>Record Payout</x-ui.button>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <h2 class="mb-3 mt-8 text-base font-bold text-ink">Payouts</h2>

    @if ($settlements->isEmpty())
        <x-ui.empty-state title="No payouts yet" description="When a courier pays you, record it here against the parcels it covers." />
    @else
        <x-ui.table stack :headers="['Payout', 'Courier', 'Collected', 'Deductions', 'Paid to you', 'Status']" data-settlements>
            @foreach ($settlements as $settlement)
                <x-ui.table-row wire:key="settlement-{{ $settlement->id }}">
                    <x-ui.table-cell primary>
                        <a href="{{ route('courier-settlements.show', $settlement) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $settlement->number() }}</a>
                        <span class="block text-xs text-ink-faint">{{ $settlement->settlement_date->format('d M Y') }}{{ $settlement->reference ? ' · '.$settlement->reference : '' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Courier">{{ $settlement->courier->label() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Collected" class="tabular-nums">Rs {{ number_format($settlement->cod_total, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Deductions" class="tabular-nums">Rs {{ number_format($settlement->deductions(), 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Paid to you" class="font-semibold tabular-nums text-ink">Rs {{ number_format($settlement->net_amount, 2) }}</x-ui.table-cell>
                    <x-ui.table-cell label="Status"><x-ui.badge :variant="$settlement->status === RecordStatus::Cancelled ? 'default' : 'success'">{{ $settlement->status === RecordStatus::Cancelled ? 'Cancelled' : 'Recorded' }}</x-ui.badge></x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
        <div class="mt-4">{{ $settlements->links() }}</div>
    @endif
</div>
