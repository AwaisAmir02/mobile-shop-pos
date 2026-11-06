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
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Goods Receipts</h1>
            <a href="{{ route('purchase-orders.index') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← Purchase Orders</a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>
            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>
        </div>
        <x-ui.export-dropdown />
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </div>

    @if ($grns->isEmpty())
        <x-ui.empty-state title="No goods receipts" description="Deliveries recorded against purchase orders show up here." />
    @else
        <x-ui.table :headers="['Receipt', 'PO', 'Vendor', 'Received', 'Products', 'By', '']">
            @foreach ($grns as $grn)
                <x-ui.table-row wire:key="grn-{{ $grn->id }}" @class(['opacity-50' => $grn->isCancelled()])>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $grn->number() }}</x-ui.table-cell>
                    <x-ui.table-cell><a href="{{ route('purchase-orders.show', $grn->purchase_order_id) }}" wire:navigate class="text-brand-700 hover:text-brand-800">{{ $grn->order?->number() ?? '—' }}</a></x-ui.table-cell>
                    <x-ui.table-cell>{{ $grn->order?->vendor?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $grn->received_on->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $grn->lines->map(fn ($line) => $line->product_name.' × '.$line->unit->amount($line->quantity))->implode(', ') }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $grn->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        @if ($grn->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @else
                            <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $grn->id }})" class="text-red-600 hover:bg-red-50">Cancel</x-ui.button>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $grns->links() }}</div>
    @endif

    <x-ui.modal name="cancel-grn-form" max-width="sm">
        <form wire:submit="cancelGrn" class="p-6">
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Receipt?</h2>
            <p class="mt-2 text-sm text-slate-600">For a delivery recorded by mistake. It takes the stock back out and removes what you owe for it. It's refused once any of this stock has been sold or written off, or anything paid. It isn't a return to the vendor.</p>

            <x-ui.error :messages="$errors->get('grn')" class="mt-3" />
            <x-ui.error :messages="$errors->get('quantity')" class="mt-3" />

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="grnCancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="grnCancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep Receipt</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelGrn" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">Cancel Receipt</x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
