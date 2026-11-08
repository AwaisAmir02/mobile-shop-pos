<?php

use App\Actions\CancelStockWriteOff;
use App\Actions\CreateStockWriteOff;
use App\Enums\QuantityUnit;
use App\Enums\WriteOffReason;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Models\StockWriteOff;
use App\ReportQueries\StockWriteOffReport;
use App\Services\TableExportService;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Stock Write-offs')] class extends Component
{
    use GuardsExportSize, Toasts, WithPagination;

    /** Arriving from a batch's "Write off" button opens the form filled in for that batch. */
    #[Url]
    public ?int $batch = null;

    public bool $openOnLoad = false;

    public string $from = '';
    public string $to = '';
    public string $reasonFilter = '';

    public int $formNonce = 0;
    public string $productId = '';
    public string $stockInId = '';
    public string $quantity = '';
    public string $reason = 'damaged';
    public string $note = '';
    public string $date = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function mount(): void
    {
        $this->date = BatchExpiry::today();

        if ($this->batch !== null && Auth::user()->hasAccessTo('stock-ins-edit-cancel')) {
            $batch = StockIn::whereNotNull('product_id')->where('quantity_remaining', '>', 0)->find($this->batch);

            if ($batch !== null) {
                $this->productId = (string) $batch->product_id;
                $this->stockInId = (string) $batch->id;
                $this->quantity = Quantity::plain($batch->quantity_remaining);
                $this->reason = $batch->isExpired() ? WriteOffReason::Expired->value : WriteOffReason::Damaged->value;
                $this->openOnLoad = true;
            }
        }
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['from', 'to', 'reasonFilter'], true)) {
            $this->resetPage();
        }
    }

    public function updatedProductId(): void
    {
        $this->stockInId = '';
    }

    protected function report(): StockWriteOffReport
    {
        return new StockWriteOffReport($this->from, $this->to, $this->reasonFilter, includeCancelled: true);
    }

    protected function selectedProduct(): ?Product
    {
        return is_numeric($this->productId) ? Product::find($this->productId) : null;
    }

    public function with(): array
    {
        $report = $this->report();
        $selected = $this->selectedProduct();

        return [
            'writeOffs' => $report->query()->latest('written_off_on')->latest('id')->paginate(15),
            'cards' => $report->summaryCards(),
            'canManage' => Auth::user()->hasAccessTo('stock-ins-edit-cancel'),
            'products' => Product::query()->where('stock_quantity', '>', 0)->orderBy('name')->get(),
            'selected' => $selected,
            'formUnit' => $selected?->unit ?? QuantityUnit::Piece,
            'batches' => $selected ? StockIn::availableBatchesFor($selected->id, $selected->sellsByExpiry())->get() : collect(),
        ];
    }

    public function openForm(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->resetForm();
        $this->dispatch('open-modal', name: 'write-off-form');
    }

    public function closeForm(): void
    {
        $this->dispatch('close-modal', name: 'write-off-form');
        $this->resetForm();
    }

    protected function resetForm(): void
    {
        $this->reset(['productId', 'stockInId', 'quantity', 'reason', 'note']);
        $this->date = BatchExpiry::today();
        $this->formNonce++;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $unit = $this->selectedProduct()?->unit ?? QuantityUnit::Piece;

        $this->validate([
            'productId' => ['required', 'integer', Rule::exists('products', 'id')->where('shop_id', Auth::user()->shop_id)],
            'stockInId' => ['nullable', 'integer'],
            'quantity' => $unit->quantityRules(),
            'reason' => ['required', Rule::enum(WriteOffReason::class)],
            'note' => [$this->reason === WriteOffReason::Other->value ? 'required' : 'nullable', 'string', 'max:500'],
            'date' => ['required', 'date', 'before_or_equal:'.BatchExpiry::today()],
        ], [], ['productId' => 'product']);

        $writeOff = CreateStockWriteOff::handle(
            Product::findOrFail($this->productId),
            $this->quantity,
            WriteOffReason::from($this->reason),
            $this->note,
            Auth::id(),
            $this->date,
            $this->stockInId !== '' ? (int) $this->stockInId : null,
        );

        $this->toastSuccess($writeOff->unit->amount($writeOff->quantity)." of {$writeOff->product_name} written off.");
        $this->closeForm();
        $this->batch = null;
    }

    public function openCancel(int $id): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->cancelingId = StockWriteOff::findOrFail($id)->id;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-write-off-form');
    }

    public function closeCancel(): void
    {
        $this->dispatch('close-modal', name: 'cancel-write-off-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }

    public function cancelWriteOff(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $writeOff = StockWriteOff::findOrFail($this->cancelingId);
        $skipped = CancelStockWriteOff::handle($writeOff, Auth::id(), $this->cancelReason);

        $this->closeCancel();
        $this->toastSuccess($skipped === [] ? 'Write-off cancelled and stock restored.' : 'Write-off cancelled. The product no longer exists, so no stock could be restored.');
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf('Stock Write-offs', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: false)));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel('Stock Write-offs', Auth::user()->shop->name, $report->filtersSummary(), $report->tableHeaders(), $report->tableRows(forExcel: true), $report->columnTypes()));
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>Stock Write-offs</x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="stock" label="Stock In sections" />

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:flex-wrap">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>
            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>
            <x-ui.field label="Reason" name="reasonFilter" for="reasonFilter" class="sm:max-w-[12rem]">
                <x-ui.select wire:model.live="reasonFilter" id="reasonFilter">
                    <option value="">All reasons</option>
                    @foreach (WriteOffReason::cases() as $option)
                        <option value="{{ $option->value }}">{{ $option->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        </div>

        <div class="flex items-center gap-3">
            <x-ui.export-dropdown />
            @if ($canManage)
                <x-ui.button type="button" wire:click="openForm">Write Off Stock</x-ui.button>
            @endif
        </div>
    </div>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ($cards as $card)
            <x-ui.stat :label="$card['label']" :value="$card['value']" :sub="$card['sub'] ?? null" />
        @endforeach
    </div>

    @if ($writeOffs->isEmpty())
        <x-ui.empty-state title="No write-offs" description="Stock removed for expiry, damage, theft or a count correction shows up here." />
    @else
        <x-ui.table :headers="['Date', 'Product', 'Quantity', 'Reason', 'Note', 'Cost', 'Entered By', '']">
            @foreach ($writeOffs as $writeOff)
                <x-ui.table-row wire:key="write-off-{{ $writeOff->id }}" @class(['opacity-50' => $writeOff->isCancelled()])>
                    <x-ui.table-cell>{{ $writeOff->written_off_on->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $writeOff->product_name }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $writeOff->unit->groupedAmount($writeOff->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($writeOff->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @else
                            {{ $writeOff->reason->label() }}
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $writeOff->note ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $writeOff->total_cost !== null ? 'Rs '.number_format($writeOff->total_cost, 2) : '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $writeOff->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        @if ($canManage && ! $writeOff->isCancelled())
                            <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $writeOff->id }})" class="text-red-600 hover:bg-red-50">Cancel</x-ui.button>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">{{ $writeOffs->links() }}</div>
    @endif

    @if ($canManage)
        <x-ui.modal name="write-off-form" max-width="lg" :show="$openOnLoad">
            <form wire:submit="save" class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Write Off Stock</h2>
                <p class="mt-1 text-sm text-slate-500">Removes stock with no sale. Your purchase records and what you owe vendors stay as they are. You can cancel it later to restore the stock.</p>

                <div class="mt-5 space-y-5" wire:key="write-off-fields-{{ $formNonce }}">
                    <x-ui.field label="Product" name="productId" for="writeOffProduct">
                        <x-ui.select wire:model.live="productId" id="writeOffProduct">
                            <option value="">Select a product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->stockLabel() }})</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    @if ($batches->isNotEmpty())
                        <x-ui.field label="Batch" name="stockInId" for="writeOffBatch" help="Leave on the first option to take from whichever batch would sell next">
                            <div wire:key="write-off-batches-{{ $productId }}">
                                <x-ui.select wire:model.live="stockInId" id="writeOffBatch">
                                    <option value="">Next batch to sell</option>
                                    @foreach ($batches as $option)
                                        <option value="{{ $option->id }}">Received {{ $option->stock_date->format('d M Y') }} · {{ $option->unit->amount($option->quantity_remaining) }}{{ $option->expiry_date ? ' · expires '.$option->expiry_date->format('d M Y') : '' }}</option>
                                    @endforeach
                                </x-ui.select>
                            </div>
                        </x-ui.field>
                    @endif

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <x-ui.field :label="$formUnit->allowsDecimals() ? 'Quantity ('.$formUnit->symbol().')' : 'Quantity'" name="quantity" for="writeOffQuantity">
                            <x-ui.input wire:model="quantity" id="writeOffQuantity" type="number" :min="$formUnit->inputMin()" :step="$formUnit->inputStep()" />
                        </x-ui.field>

                        <x-ui.field label="Date" name="date" for="writeOffDate">
                            <x-ui.input wire:model="date" id="writeOffDate" type="date" />
                        </x-ui.field>
                    </div>

                    <x-ui.field label="Reason" name="reason" for="writeOffReason">
                        <x-ui.select wire:model.live="reason" id="writeOffReason">
                            @foreach (WriteOffReason::cases() as $option)
                                <option value="{{ $option->value }}">{{ $option->label() }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>

                    <x-ui.field label="Note" name="note" for="writeOffNote" :help="$reason === 'other' ? 'Required: say what happened' : 'Optional'">
                        <x-ui.input wire:model="note" id="writeOffNote" />
                    </x-ui.field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeForm">Cancel</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save">Write Off</x-ui.button>
                </div>
            </form>
        </x-ui.modal>

        <x-ui.modal name="cancel-write-off-form" max-width="sm">
            <form wire:submit="cancelWriteOff" class="p-6">
                <h2 class="text-lg font-semibold text-slate-900">Cancel This Write-off?</h2>
                <p class="mt-2 text-sm text-slate-600">The stock goes back into the batches it came from. This cannot be undone.</p>

                <div class="mt-5">
                    <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                        <x-ui.input wire:model="cancelReason" id="cancelReason" />
                    </x-ui.field>
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <x-ui.button type="button" variant="secondary" wire:click="closeCancel">Keep It</x-ui.button>
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelWriteOff" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">Cancel Write-off</x-ui.button>
                </div>
            </form>
        </x-ui.modal>
    @endif
</div>
