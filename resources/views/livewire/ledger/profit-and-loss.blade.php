<?php

use App\Livewire\Concerns\LedgerReportScreen;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Profit & Loss')] class extends Component
{
    use LedgerReportScreen;

    public string $from = '';
    public string $to = '';

    public function mount(): void
    {
        $this->from = Carbon::now('Asia/Karachi')->startOfMonth()->toDateString();
        $this->to = Carbon::now('Asia/Karachi')->toDateString();
        $this->catchUp();
    }

    public function with(): array
    {
        return [
            'running' => $this->ledgerRunning(),
            'failed' => $this->failedPostings(),
            'statement' => $this->statements()->profitAndLoss($this->from ?: null, $this->to ?: null),
            'noCost' => DB::table('sale_items')->where('shop_id', Auth::user()->shop_id)->whereNull('cost_price')->count(),
        ];
    }

    protected function reportTitle(): string
    {
        return 'Profit & Loss';
    }

    protected function reportFilters(): string
    {
        return $this->periodLabel($this->from, $this->to);
    }

    protected function reportHeaders(): array
    {
        return ['Account', 'Amount'];
    }

    protected function reportColumnTypes(): array
    {
        return ['string', 'currency'];
    }

    protected function reportRows(bool $forExcel): array
    {
        $statement = $this->statements()->profitAndLoss($this->from ?: null, $this->to ?: null);
        $money = fn (float $amount) => $this->money($amount, $forExcel);
        $rows = array_map(fn (array $row) => [$row['name'], $money($row['amount'])], $statement['income']);
        $rows[] = ['Total Income', $money($statement['total_income'])];
        $rows[] = ['Cost of Goods Sold', $money($statement['cogs'])];
        $rows[] = ['Gross Profit', $money($statement['gross_profit'])];

        foreach ($statement['expenses'] as $row) {
            $rows[] = [$row['name'], $money($row['amount'])];
        }

        $rows[] = ['Total Expenses', $money($statement['total_expenses'])];
        $rows[] = ['Net Profit', $money($statement['net_profit'])];

        return $rows;
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Profit & Loss</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.profit-and-loss" />

    @unless ($running)
        <x-ui.empty-state title="The ledger hasn't started" description="The owner can set it up from the Trial Balance screen." />
    @else
        <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to)">
            <x-ui.date-range />

            <x-slot:actions>
                <x-ui.export-dropdown />
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ui.note>This won't match Net on your Dashboard. Net is Revenue − Expenses, without the cost of what was sold. This is real profit from the ledger.</x-ui.note>

        <x-ledger.warnings :failed="$failed" :no-cost="$noCost" />

        <x-ui.stat-row>
            <x-ui.stat label="Total Income" value="Rs {{ number_format($statement['total_income'], 2) }}" />
            <x-ui.stat label="Gross Profit" value="Rs {{ number_format($statement['gross_profit'], 2) }}" sub="After the cost of goods sold" />
            <x-ui.stat label="Total Expenses" value="Rs {{ number_format($statement['total_expenses'], 2) }}" />
            <x-ui.stat label="Net Profit" value="Rs {{ number_format($statement['net_profit'], 2) }}" />
        </x-ui.stat-row>

        <x-ui.card :padding="false" class="max-w-3xl">
            <dl class="divide-y divide-line text-sm">
                <div class="px-5 pb-2 pt-4 text-xs font-bold uppercase tracking-wider text-ink-faint">Income</div>
                @foreach ($statement['income'] as $row)
                    <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-ink-muted">{{ $row['name'] }}</dt><dd class="tabular-nums">Rs {{ number_format($row['amount'], 2) }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-4 px-5 py-2.5 font-medium text-ink"><dt>Total Income</dt><dd class="tabular-nums">Rs {{ number_format($statement['total_income'], 2) }}</dd></div>
                <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-ink-muted">Cost of Goods Sold</dt><dd class="tabular-nums">Rs {{ number_format($statement['cogs'], 2) }}</dd></div>
                <div class="flex justify-between gap-4 bg-surface-sunken px-5 py-2.5 font-semibold text-ink"><dt>Gross Profit</dt><dd class="tabular-nums">Rs {{ number_format($statement['gross_profit'], 2) }}</dd></div>
                <div class="px-5 pb-2 pt-4 text-xs font-bold uppercase tracking-wider text-ink-faint">Expenses</div>
                @foreach ($statement['expenses'] as $row)
                    <div class="flex justify-between gap-4 px-5 py-2.5"><dt class="text-ink-muted">{{ $row['name'] }}</dt><dd class="tabular-nums">Rs {{ number_format($row['amount'], 2) }}</dd></div>
                @endforeach
                <div class="flex justify-between gap-4 px-5 py-2.5 font-medium text-ink"><dt>Total Expenses</dt><dd class="tabular-nums">Rs {{ number_format($statement['total_expenses'], 2) }}</dd></div>
                <div class="flex justify-between gap-4 bg-surface-sunken px-5 py-3 text-base font-bold text-ink"><dt>Net Profit</dt><dd class="tabular-nums">Rs {{ number_format($statement['net_profit'], 2) }}</dd></div>
            </dl>
        </x-ui.card>
    @endunless
</div>
