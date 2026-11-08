<?php

use App\Actions\CancelGrn;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\Grn;
use App\ReportQueries\GrnReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Goods Receipts')] class extends Component
{
    use GuardsExportSize, Toasts, WithPagination;

    #[Url]
    public ?int $po = null;

    public string $from = '';
    public string $to = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function updating(string $name): void
    {
        if (in_array($name, ['from', 'to'], true)) {
            $this->resetPage();
        }
    }

    protected function report(): GrnReport
    {
        return new GrnReport($this->from, $this->to, $this->po);
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'grns' => $report->query()->latest('received_on')->latest('id')->paginate(15),
            'cards' => $report->summaryCards(),
        ];
    }

    public function openCancel(int $id): void
    {
        $this->cancelingId = Grn::inCurrentBranch()->findOrFail($id)->id;
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cancel-grn-form');
    }

    public function closeCancel(): void
    {
        $this->dispatch('close-modal', name: 'cancel-grn-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }

    public function cancelGrn(): void
    {
        CancelGrn::handle(Grn::inCurrentBranch()->findOrFail($this->cancelingId), Auth::id(), $this->cancelReason);

        $this->closeCancel();
        $this->toastSuccess('Receipt cancelled and its stock taken back out.');
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Goods Receipts', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Goods Receipts', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Goods Receipts</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="purchasing" label="Purchasing sections" />

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to)">
        <x-ui.date-range />

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.stat-row class="lg:grid-cols-3">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </x-ui.stat-row>

    @if ($grns->isEmpty())
        <x-ui.empty-state title="No goods receipts" description="Deliveries recorded against purchase orders show up here." />
    @else
        <x-ui.table stack :headers="['Receipt', 'PO', 'Vendor', 'Products', '']">
            @foreach ($grns as $grn)
                <x-ui.table-row wire:key="grn-{{ $grn->id }}" @class(['opacity-50' => $grn->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        {{ $grn->number() }}
                        <span class="ui-sub">{{ $grn->received_on->format('d M Y') }} · {{ $grn->user?->name ?? '—' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="PO"><a href="{{ route('purchase-orders.show', $grn->purchase_order_id) }}" wire:navigate class="font-semibold text-link hover:underline">{{ $grn->order?->number() ?? '—' }}</a></x-ui.table-cell>
                    <x-ui.table-cell label="Vendor">{{ $grn->order?->vendor?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell label="Products" class="!whitespace-normal">
                        <span class="block max-w-md">{{ $grn->lines->map(fn ($line) => $line->product_name.' × '.$line->unit->amount($line->quantity))->implode(', ') }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        @if ($grn->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @else
                            <x-ui.row-actions label="Actions for {{ $grn->number() }}">
                                <x-ui.menu-item danger wire:click="openCancel({{ $grn->id }})">Cancel</x-ui.menu-item>
                            </x-ui.row-actions>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $grns->links() }}</div>
    @endif

    <x-ui.modal name="cancel-grn-form" max-width="sm">
        <form wire:submit="cancelGrn" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Receipt?</h2>
            <p class="mt-2 text-sm text-ink-muted">For a delivery recorded by mistake. It takes the stock back out and removes what you owe for it. It's refused once any of this stock has been sold or written off, or anything paid. It isn't a return to the vendor.</p>

            <x-ui.error :messages="$errors->get('grn')" class="mt-3" />
            <x-ui.error :messages="$errors->get('quantity')" class="mt-3" />

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="grnCancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="grnCancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep Receipt</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelGrn" variant="danger">Cancel Receipt</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
