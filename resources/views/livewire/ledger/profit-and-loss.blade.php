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
        <h1 class="text-xl font-semibold text-slate-900">Profit & Loss</h1>
    </x-slot>

    <x-ledger.nav active="ledger.profit-and-loss" />

    @unless ($running)
        <x-ui.empty-state title="The ledger hasn't started" description="The owner can set it up from the Trial Balance screen." />
    @else
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-ui.field label="From" name="from" for="plFrom" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="from" id="plFrom" type="date" />
                </x-ui.field>
                <x-ui.field label="To" name="to" for="plTo" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="to" id="plTo" type="date" />
                </x-ui.field>
            </div>
            <x-ui.export-dropdown />
        </div>

        <p class="mb-4 text-sm text-slate-600">This won't match Net on your Dashboard. Net is Revenue − Expenses, without the cost of what was sold. This is real profit from the ledger.</p>

        <x-ledger.warnings :failed="$failed" :no-cost="$noCost" />

        <div class="max-w-2xl">
            <x-ui.card :padding="false">
                <dl class="divide-y divide-slate-100 text-sm">
                    @foreach ($statement['income'] as $row)
                        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-600">{{ $row['name'] }}</dt><dd>Rs {{ number_format($row['amount'], 2) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between px-5 py-2.5 font-medium text-slate-900"><dt>Total Income</dt><dd>Rs {{ number_format($statement['total_income'], 2) }}</dd></div>
                    <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-600">Cost of Goods Sold</dt><dd>Rs {{ number_format($statement['cogs'], 2) }}</dd></div>
                    <div class="flex justify-between bg-slate-50 px-5 py-2.5 font-semibold text-slate-900"><dt>Gross Profit</dt><dd>Rs {{ number_format($statement['gross_profit'], 2) }}</dd></div>
                    @foreach ($statement['expenses'] as $row)
                        <div class="flex justify-between px-5 py-2.5"><dt class="text-slate-600">{{ $row['name'] }}</dt><dd>Rs {{ number_format($row['amount'], 2) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between px-5 py-2.5 font-medium text-slate-900"><dt>Total Expenses</dt><dd>Rs {{ number_format($statement['total_expenses'], 2) }}</dd></div>
                    <div class="flex justify-between bg-slate-50 px-5 py-3 text-base font-semibold text-slate-900"><dt>Net Profit</dt><dd>Rs {{ number_format($statement['net_profit'], 2) }}</dd></div>
                </dl>
            </x-ui.card>
        </div>
    @endunless
</div>
