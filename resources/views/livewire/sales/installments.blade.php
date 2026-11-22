<?php

use App\Models\Sale;
use App\Support\BranchContext;
use App\Support\InstallmentSchedule;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Every sale on an instalment plan, late ones first, so the counter knows who
 * to call. Amounts come from InstallmentSchedule, i.e. from the sale's own
 * payments, the same figures as the customer's udhaar page and reminder.
 */
new #[Layout('layouts.app')] #[Title('Instalments')] class extends Component
{
    #[Url(except: 'open')]
    public string $show = 'open';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->sellsDevices(), 404);
    }

    public function with(): array
    {
        // A customer's dues are shop-wide, so the plans are too.
        $plans = BranchContext::current()->shopWide(fn () => Sale::query()
            ->whereHas('installments')
            ->with(['installments', 'customer'])
            ->withSum('payments', 'amount')->withSum('returns', 'due_reduction')
            ->latest('id')
            ->get())
            ->map(fn (Sale $sale) => ['sale' => $sale, 'schedule' => new InstallmentSchedule($sale)]);

        $open = $plans->reject(fn ($plan) => $plan['schedule']->isComplete());

        $rows = match ($this->show) {
            'overdue' => $open->filter(fn ($plan) => $plan['schedule']->dueNowAmount() > 0),
            'paid' => $plans->filter(fn ($plan) => $plan['schedule']->isComplete()),
            default => $open,
        };

        // Late first (oldest late date first), then by the next date to come.
        $rows = $rows->sortBy(fn ($plan) => [
            $plan['schedule']->dueNowAmount() > 0 ? 0 : 1,
            (($plan['schedule']->oldestOverdue() ?? $plan['schedule']->next())['due_date'] ?? null)?->toDateString() ?? '9999-12-31',
        ])->values();

        return [
            'rows' => $rows,
            'openCount' => $open->count(),
            'overdueTotal' => round((float) $open->sum(fn ($plan) => $plan['schedule']->dueNowAmount()), 2),
            'outstandingTotal' => round((float) $open->sum(fn ($plan) => $plan['sale']->amountDue()), 2),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Sales paid monthly, late ones first">
            Instalments
            <x-slot:actions>
                <x-ui.button href="{{ route('sales.index') }}" wire:navigate size="sm">New Sale</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="sales" label="Sales sections" />

    <x-ui.stat-row>
        <x-ui.stat label="Open Plans" :value="number_format($openCount)" />
        <x-ui.stat label="Due Now" :value="'Rs '.number_format($overdueTotal, 2)" />
        <x-ui.stat label="Still to Collect" :value="'Rs '.number_format($outstandingTotal, 2)" />
    </x-ui.stat-row>

    <div class="mb-4 inline-flex gap-1 rounded-full border border-line bg-surface-sunken/60 p-1 text-sm font-semibold" role="group" aria-label="Show">
        @foreach (['open' => 'Open', 'overdue' => 'Due now', 'paid' => 'Paid off'] as $value => $label)
            <button type="button" wire:click="$set('show', '{{ $value }}')" aria-pressed="{{ $show === $value ? 'true' : 'false' }}" @class([
                'rounded-full px-3 py-1.5 transition-colors',
                'bg-surface text-ink shadow-soft ring-1 ring-line' => $show === $value,
                'text-ink-faint hover:text-ink' => $show !== $value,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($rows->isEmpty())
        <x-ui.empty-state
            :title="$show === 'paid' ? 'No plans paid off yet' : ($show === 'overdue' ? 'Nothing is due right now' : 'No open instalment plans')"
            description="On the sales register, choose Instalments under Payment to sell on a monthly plan."
        />
    @else
        <x-ui.table stack :headers="['Invoice', 'Customer', 'Status', 'Paid', 'Left']">
            @foreach ($rows as $plan)
                @php($sale = $plan['sale'])
                @php($schedule = $plan['schedule'])
                @php($paidCount = $schedule->rows()->where('status', 'paid')->count())
                <x-ui.table-row wire:key="plan-{{ $sale->id }}" data-installment-plan="{{ $sale->id }}">
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        <a href="{{ route('sales.show', $sale) }}" wire:navigate class="text-link hover:underline">{{ $sale->invoiceNumber() }}</a>
                        <span class="ui-sub">{{ $sale->created_at->format('d M Y') }} · Rs {{ number_format($sale->total, 2) }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Customer">
                        @if ($sale->customer && auth()->user()->hasAccessTo('udhaar'))
                            <a href="{{ route('udhaar.show', $sale->customer) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $sale->customer->name }}</a>
                        @else
                            <span class="font-semibold text-ink">{{ $sale->customer?->name ?? '—' }}</span>
                        @endif
                        @if ($sale->customer?->phone)
                            <span class="ui-sub">{{ $sale->customer->phone }}</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Status">
                        <span @class(['font-semibold', 'text-danger' => $schedule->dueNowAmount() > 0, 'text-ink' => $schedule->dueNowAmount() <= 0])>{{ $schedule->summary() }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Paid">{{ $paidCount }} of {{ $schedule->count() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Left" class="font-semibold tabular-nums text-ink">Rs {{ number_format($sale->amountDue(), 2) }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif
</div>
