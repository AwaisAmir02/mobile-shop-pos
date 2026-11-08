<?php

use App\Actions\UpdateStockIn;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\StockIn;
use App\ReportQueries\ExpiryReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Expiring & Expired')] class extends Component
{
    use GuardsExportSize, Toasts;

    public const WINDOWS = [7, 30, 60, 90];

    public string $days = '30';

    public ?int $datingId = null;
    public string $datingName = '';
    public string $datingDate = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->shop?->expiry_tracking_enabled, 403);
    }

    protected function report(): ExpiryReport
    {
        return new ExpiryReport(in_array((int) $this->days, self::WINDOWS, true) ? (int) $this->days : 30);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'cards' => $report->summaryCards(),
            'expired' => $report->expired(),
            'soon' => $report->expiringSoon(),
            'undated' => $report->undated(),
            'canEdit' => Auth::user()->hasAccessTo('stock-ins-edit-cancel'),
        ];
    }

    public function openDate(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $batch = StockIn::findOrFail($id);

        $this->datingId = $batch->id;
        $this->datingName = $batch->product_name;
        $this->datingDate = $batch->expiry_date?->toDateString() ?? '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'batch-expiry-form');
    }

    public function saveDate(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->validate(['datingDate' => ['required', 'date']], [], ['datingDate' => 'expiry date']);

        $batch = StockIn::findOrFail($this->datingId);

        // Through the normal edit, so the change leaves a revision like any other.
        UpdateStockIn::handle($batch, [
            'quantity' => $batch->quantity,
            'vendor_id' => $batch->vendor_id,
            'stock_date' => $batch->stock_date->toDateString(),
            'note' => $batch->note,
            'cost_price' => $batch->cost_price !== null ? (float) $batch->cost_price : null,
            'selling_price' => $batch->selling_price !== null ? (float) $batch->selling_price : null,
            'expiry_date' => $this->datingDate,
        ], Auth::id());

        $this->toastSuccess('Expiry date saved.');
        $this->closeDate();
    }

    public function closeDate(): void
    {
        $this->dispatch('close-modal', name: 'batch-expiry-form');
        $this->reset(['datingId', 'datingName', 'datingDate']);
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Expiring & Expired', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Expiring & Expired', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Expiring &amp; Expired</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="stock" label="Stock In sections" />

    <x-ui.filter-bar>
        <x-ui.field label="Expiring within" name="days" for="days" class="w-full sm:w-48">
            <x-ui.select wire:model.live="days" id="days">
                @foreach ($this::WINDOWS as $window)
                    <option value="{{ $window }}">{{ $window }} days</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.stat-row class="lg:grid-cols-3">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </x-ui.stat-row>

    <div class="space-y-6">
        @foreach ([['Expired', 'Can no longer be sold. Write them off to clear them from your stock and its value.', $expired, 'expired'], ['Expiring soon', 'Sold first, soonest date first.', $soon, 'soon'], ['No expiry date', 'Sold after dated stock. Fill in a date to bring old batches into the order.', $undated, 'undated']] as [$title, $description, $batches, $section])
            <x-ui.card :title="$title" :description="$description" :padding="false" wire:key="expiry-section-{{ $section }}">
                @if ($batches->isEmpty())
                    <p class="px-5 py-4 text-sm text-ink-faint">Nothing here.</p>
                @else
                    <x-ui.table stack :headers="['Product', 'Received', 'Expires', 'Remaining', 'Value at Cost', '']">
                        @foreach ($batches as $batch)
                            <x-ui.table-row wire:key="expiry-batch-{{ $batch->id }}">
                                <x-ui.table-cell primary class="font-semibold text-ink">{{ $batch->product_name }}</x-ui.table-cell>
                                <x-ui.table-cell label="Received">{{ $batch->stock_date->format('d M Y') }}</x-ui.table-cell>
                                <x-ui.table-cell label="Expires"><span @class(['font-semibold text-danger' => $section === 'expired', 'text-warning' => $section === 'soon'])>{{ $batch->expiry_date?->format('d M Y') ?? '—' }}</span></x-ui.table-cell>
                                <x-ui.table-cell label="Remaining">{{ $batch->unit->groupedAmount($batch->quantity_remaining) }}</x-ui.table-cell>
                                <x-ui.table-cell label="Value at Cost">Rs {{ number_format(\App\ReportQueries\ExpiryReport::valueAtCost($batch), 2) }}</x-ui.table-cell>
                                <x-ui.table-cell actions align="right">
                                    @if ($canEdit)
                                        <x-ui.row-actions label="Actions for {{ $batch->product_name }}">
                                            <x-ui.menu-item wire:click="openDate({{ $batch->id }})">{{ $batch->expiry_date ? 'Change date' : 'Set date' }}</x-ui.menu-item>
                                            <x-ui.menu-divider />
                                            <x-ui.menu-item href="{{ route('stock-ins.write-offs', ['batch' => $batch->id]) }}" wire:navigate :danger="$section === 'expired'">Write off</x-ui.menu-item>
                                        </x-ui.row-actions>
                                    @endif
                                </x-ui.table-cell>
                            </x-ui.table-row>
                        @endforeach
                    </x-ui.table>
                @endif
            </x-ui.card>
        @endforeach
    </div>

    <x-ui.modal name="batch-expiry-form" max-width="sm">
        <form wire:submit="saveDate" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Expiry date</h2>
            <p class="mt-1 text-sm text-ink-faint">{{ $datingName }}</p>

            <div class="mt-5">
                <x-ui.field label="Expires on" name="datingDate" for="datingDate" help="The last day it can be sold">
                    <x-ui.input wire:model="datingDate" id="datingDate" type="date" />
                    <x-ui.error :messages="$errors->get('quantity')" class="mt-1" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeDate">Cancel</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveDate">Save</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
