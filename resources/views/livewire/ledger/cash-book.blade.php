<?php

use App\Exports\TableExport;
use App\Livewire\Concerns\LedgerReportScreen;
use App\Services\TableExportService;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Cash Book')] class extends Component
{
    use LedgerReportScreen;

    public string $from = '';
    public string $to = '';
    public string $account = '';

    public function mount(): void
    {
        $this->from = Carbon::now('Asia/Karachi')->startOfMonth()->toDateString();
        $this->to = Carbon::now('Asia/Karachi')->toDateString();
        $this->catchUp();
    }

    public function with(): array
    {
        $statements = $this->statements();

        return [
            'running' => $this->ledgerRunning(),
            'failed' => $this->failedPostings(),
            'accounts' => $statements->cashAccounts(),
            'book' => $statements->cashBook($this->account, $this->from ?: null, $this->to ?: null),
        ];
    }

    protected function reportTitle(): string
    {
        return 'Cash Book';
    }

    protected function reportFilters(): string
    {
        return $this->periodLabel($this->from, $this->to);
    }

    protected function reportHeaders(): array
    {
        return ['Date', 'Description', 'Account', 'In', 'Out', 'Balance'];
    }

    protected function reportColumnTypes(): array
    {
        return ['date', 'string', 'string', 'currency', 'currency', 'currency'];
    }

    protected function reportRows(bool $forExcel): array
    {
        $book = $this->statements()->cashBook($this->account, $this->from ?: null, $this->to ?: null);

        return array_map(fn (array $row) => [
            $forExcel ? TableExport::excelDate(Carbon::parse($row['date'])) : Carbon::parse($row['date'])->format('d M Y'),
            TableExportService::sanitizeCell($row['description']),
            TableExportService::sanitizeCell($row['account']),
            $this->money($row['in'], $forExcel),
            $this->money($row['out'], $forExcel),
            $this->money($row['balance'], $forExcel),
        ], $book['rows']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Cash Book</x-ui.page-header>
    </x-slot>

    <x-ledger.nav active="ledger.cash-book" />

    @unless ($running)
        <x-ui.empty-state title="The ledger hasn't started" description="The owner can set it up from the Trial Balance screen." />
    @else
        <x-ui.filter-bar :active="(int) filled($account)">
                <x-ui.field label="Account" name="account" for="bookAccount" class="w-full sm:w-56">
                    <x-ui.select wire:model.live="account" id="bookAccount">
                        <option value="">Cash and all accounts</option>
                        @foreach ($accounts as $option)
                            <option value="{{ $option->code }}">{{ $option->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
                <x-ui.date-range />

            <x-slot:actions>
                <x-ui.export-dropdown />
            </x-slot:actions>
        </x-ui.filter-bar>

        <x-ledger.warnings :failed="$failed" />

        <x-ui.note>Only money recorded against Cash or an account shows here. Money with no recorded method is in Receipts Not Classified on the Trial Balance.</x-ui.note>

        <x-ui.stat-row>
            <x-ui.stat label="Opening" value="Rs {{ number_format($book['opening'], 2) }}" />
            <x-ui.stat label="In" value="Rs {{ number_format($book['total_in'], 2) }}" />
            <x-ui.stat label="Out" value="Rs {{ number_format($book['total_out'], 2) }}" />
            <x-ui.stat label="Closing" value="Rs {{ number_format($book['closing'], 2) }}" />
        </x-ui.stat-row>

        @if ($book['rows'] === [])
            <x-ui.empty-state title="No money moved" description="Nothing went through these accounts in this period." />
        @else
            <x-ui.table stack :headers="['Date', 'Description', 'Account', 'In', 'Out', 'Balance']">
                @foreach ($book['rows'] as $row)
                    <x-ui.table-row wire:key="book-{{ $loop->index }}">
                        <x-ui.table-cell primary>{{ \Carbon\Carbon::parse($row['date'])->format('d M Y') }}</x-ui.table-cell>
                        <x-ui.table-cell label="Description" class="whitespace-normal">{{ $row['description'] }}</x-ui.table-cell>
                        <x-ui.table-cell label="Account">{{ $row['account'] }}</x-ui.table-cell>
                        <x-ui.table-cell label="In">{{ $row['in'] ? 'Rs '.number_format($row['in'], 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Out">{{ $row['out'] ? 'Rs '.number_format($row['out'], 2) : '—' }}</x-ui.table-cell>
                        <x-ui.table-cell label="Balance" class="font-medium text-ink">Rs {{ number_format($row['balance'], 2) }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
        @endif
    @endunless
</div>
