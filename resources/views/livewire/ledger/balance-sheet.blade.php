<?php

use App\Ledger\ChartOfAccounts as Chart;
use App\Livewire\Concerns\LedgerReportScreen;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Balance Sheet')] class extends Component
{
    use LedgerReportScreen;

    public string $asOf = '';

    public function mount(): void
    {
        $this->asOf = Carbon::now('Asia/Karachi')->toDateString();
        $this->catchUp();
    }

    public function with(): array
    {
        $sheet = $this->statements()->balanceSheet($this->asOf ?: null);

        return [
            'running' => $this->ledgerRunning(),
            'failed' => $this->failedPostings(),
            'sheet' => $sheet,
            'notClassified' => collect($sheet['assets'])->firstWhere('name', Chart::ACCOUNTS[Chart::NOT_CLASSIFIED][0])['amount'] ?? 0,
        ];
    }

    protected function reportTitle(): string
    {
        return 'Balance Sheet';
    }

    protected function reportFilters(): string
    {
        return 'As of '.($this->asOf ?: 'today');
    }

    protected function reportHeaders(): array
    {
        return ['Section', 'Account', 'Amount'];
    }

    protected function reportColumnTypes(): array
    {
        return ['string', 'string', 'currency'];
    }

    protected function reportRows(bool $forExcel): array
    {
        $sheet = $this->statements()->balanceSheet($this->asOf ?: null);
        $rows = [];

        foreach (['Assets' => ['assets', 'total_assets'], 'Liabilities' => ['liabilities', 'total_liabilities'], 'Equity' => ['equity', 'total_equity']] as $section => [$key, $total]) {
            foreach ($sheet[$key] as $row) {
                $rows[] = [$section, $row['name'], $this->money($row['amount'], $forExcel)];
            }

            $rows[] = [$section, 'Total '.$section, $this->money($sheet[$total], $forExcel)];
        }

        return $rows;
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Balance Sheet</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.balance-sheet" />

    @unless ($running)
        <x-ui.empty-state title="The ledger hasn't started" description="The owner can set it up from the Trial Balance screen." />
    @else
        <x-ui.filter-bar>
            <x-ui.field label="As of" name="asOf" for="sheetAsOf" class="w-full sm:w-44">
                <x-ui.input wire:model.live="asOf" id="sheetAsOf" type="date" />
            </x-ui.field>

            <x-slot:actions>
                <x-ui.export-dropdown />
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ledger.warnings :failed="$failed" />

        @if (! $sheet['balanced'])
            <div class="mb-4 rounded-xl border border-danger/20 bg-danger-soft px-4 py-3 text-sm text-danger">These don't balance, which shouldn't happen. Please check Ledger Health.</div>
        @endif

        @if ($notClassified != 0)
            <x-ui.note>Rs {{ number_format(abs($notClassified), 2) }} of money moved with no record of which account it used, so Cash and Bank here aren't the full picture. Choosing Paid From on new records keeps this from growing.</x-ui.note>
        @endif

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <x-ui.card title="Assets" :padding="false">
                <dl class="divide-y divide-line text-sm">
                    @foreach ($sheet['assets'] as $row)
                        <div class="flex justify-between px-5 py-2.5"><dt class="text-ink-muted">{{ $row['name'] }}</dt><dd>Rs {{ number_format($row['amount'], 2) }}</dd></div>
                    @endforeach
                    <div class="flex justify-between bg-surface-sunken px-5 py-3 font-semibold text-ink"><dt>Total Assets</dt><dd>Rs {{ number_format($sheet['total_assets'], 2) }}</dd></div>
                </dl>
            </x-ui.card>

            <div class="space-y-6">
                <x-ui.card title="Liabilities" :padding="false">
                    <dl class="divide-y divide-line text-sm">
                        @foreach ($sheet['liabilities'] as $row)
                            <div class="flex justify-between px-5 py-2.5"><dt class="text-ink-muted">{{ $row['name'] }}</dt><dd>Rs {{ number_format($row['amount'], 2) }}</dd></div>
                        @endforeach
                        <div class="flex justify-between bg-surface-sunken px-5 py-3 font-semibold text-ink"><dt>Total Liabilities</dt><dd>Rs {{ number_format($sheet['total_liabilities'], 2) }}</dd></div>
                    </dl>
                </x-ui.card>
                <x-ui.card title="Equity" :padding="false">
                    <dl class="divide-y divide-line text-sm">
                        @foreach ($sheet['equity'] as $row)
                            <div class="flex justify-between px-5 py-2.5"><dt class="text-ink-muted">{{ $row['name'] }}</dt><dd>Rs {{ number_format($row['amount'], 2) }}</dd></div>
                        @endforeach
                        <div class="flex justify-between bg-surface-sunken px-5 py-3 font-semibold text-ink"><dt>Total Equity</dt><dd>Rs {{ number_format($sheet['total_equity'], 2) }}</dd></div>
                    </dl>
                </x-ui.card>
            </div>
        </div>
    @endunless
</div>
