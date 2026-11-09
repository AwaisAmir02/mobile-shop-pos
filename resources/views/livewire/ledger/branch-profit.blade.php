<?php

use App\Livewire\Concerns\LedgerReportScreen;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Branch Profit')] class extends Component
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
            'report' => $this->statements()->branchProfitAndLoss($this->from ?: null, $this->to ?: null),
        ];
    }

    protected function reportTitle(): string
    {
        return 'Branch Profit';
    }

    protected function reportFilters(): string
    {
        return $this->periodLabel($this->from, $this->to);
    }

    protected function reportHeaders(): array
    {
        return ['Account', ...$this->statements()->branchProfitAndLoss($this->from ?: null, $this->to ?: null)['columns']];
    }

    protected function reportColumnTypes(): array
    {
        return ['string', ...array_fill(0, count($this->reportHeaders()) - 1, 'currency')];
    }

    protected function reportRows(bool $forExcel): array
    {
        return array_map(
            fn (array $row) => [$row['name'], ...array_map(fn (float $amount) => $this->money($amount, $forExcel), $row['amounts'])],
            $this->statements()->branchProfitAndLoss($this->from ?: null, $this->to ?: null)['rows'],
        );
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Branch Profit</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.branch-profit" />

    @unless ($running)
        <x-ui.empty-state title="The ledger hasn't started" description="The owner can set it up from the Trial Balance screen." />
    @else
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <x-ui.field label="From" name="from" for="branchFrom" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="from" id="branchFrom" type="date" />
                </x-ui.field>
                <x-ui.field label="To" name="to" for="branchTo" class="sm:max-w-[10rem]">
                    <x-ui.input wire:model.live="to" id="branchTo" type="date" />
                </x-ui.field>
            </div>
            <x-ui.export-dropdown />
        </div>

        <x-ledger.warnings :failed="$failed" />

        <p class="mb-4 text-sm text-ink-muted">Stock and bank accounts are shared across branches, so this shows profit only. Costs with no branch stay in their own column rather than being shared out by a guess.</p>

        <x-ui.table :headers="['Account', ...$report['columns']]">
            @foreach ($report['rows'] as $row)
                <x-ui.table-row wire:key="branch-row-{{ $loop->index }}" @class(['bg-surface-sunken' => $row['emphasis']])>
                    <x-ui.table-cell @class(['font-semibold text-ink' => $row['emphasis']])>{{ $row['name'] }}</x-ui.table-cell>
                    @foreach ($row['amounts'] as $amount)
                        <x-ui.table-cell @class(['font-semibold text-ink' => $row['emphasis']])>Rs {{ number_format($amount, 2) }}</x-ui.table-cell>
                    @endforeach
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endunless
</div>
