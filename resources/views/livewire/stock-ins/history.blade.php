<?php

use App\Actions\CancelStockIn;
use App\Actions\RecordStockInPayment;
use App\Actions\UpdateStockIn;
use App\Actions\VendorInvoices;
use App\Enums\PaymentStatus;
use App\Enums\QuantityUnit;
use App\Livewire\Concerns\GuardsExportSize;
use App\Livewire\Concerns\Toasts;
use App\Models\Product;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Models\Vendor;
use App\ReportQueries\StockInReport;
use App\Services\TableExportService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

new #[Layout('layouts.app')] #[Title('Stock In History')] class extends Component
{
    use \App\Livewire\Concerns\ChoosesPaymentSource;

    use GuardsExportSize, Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    // From the URL too, so a product's page can link straight to its batches.
    #[Url]
    public string $productFilter = '';
    public string $paymentStatusFilter = '';

    #[Url]
    public string $vendorFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editQuantity = '';
    public string $editUnit = 'piece';
    public bool $editFromGrn = false;
    public bool $editCostLocked = false;
    public string $editVendorId = '';
    public string $editStockDate = '';
    public string $editNote = '';
    public string $editCostPrice = '';
    public string $editSellingPrice = '';
    public string $editExpiryDate = '';

    public ?int $cancelingId = null;
    public string $cancelReason = '';

    public function updatingFrom(): void
    {
        $this->resetPage();
    }

    public function updatingTo(): void
    {
        $this->resetPage();
    }

    public function updatingProductFilter(): void
    {
        $this->resetPage();
    }

    public function updatingPaymentStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatingVendorFilter(): void
    {
        $this->resetPage();
    }

    protected function report(): StockInReport
    {
        return new StockInReport(
            $this->from,
            $this->to,
            $this->productFilter,
            $this->paymentStatusFilter,
            Auth::user()->hasAccessTo('vendors') ? $this->vendorFilter : '',
            includeCancelled: true,
        );
    }

    public function with(): array
    {
        $report = $this->report();

        return [
            'entries' => $report->query()->latest('stock_date')->latest('id')->paginate(15),
            'totalUnits' => $report->totalUnits(),
            'measuredTotals' => QuantityUnit::summarize($report->measuredTotals()),
            'showExpiry' => $report->showsExpiry(),
            'expiryEnabled' => $this->expiryEnabled(),
            'totalOwed' => $report->totalOwed(),
            'products' => Product::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
            'vendors' => Auth::user()->hasAccessTo('vendors') ? Vendor::query()->orderBy('name')->get() : collect(),
        ];
    }

    protected function expiryEnabled(): bool
    {
        return (bool) Auth::user()->shop?->expiry_tracking_enabled;
    }

    public function exportPdf(TableExportService $exportService): ?StreamedResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toPdf(
            'Stock In History',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: false),
        ));
    }

    public function exportExcel(TableExportService $exportService): ?BinaryFileResponse
    {
        $report = $this->report();

        return $this->guardExportSize(fn () => $exportService->toExcel(
            'Stock In History',
            Auth::user()->shop->name,
            $report->filtersSummary(),
            $report->tableHeaders(),
            $report->tableRows(forExcel: true),
            $report->columnTypes(),
        ));
    }

    public function clearFilters(): void
    {
        $this->reset(['from', 'to', 'productFilter', 'paymentStatusFilter', 'vendorFilter']);
    }

    public function markAsPaid(int $id): void
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->inCurrentBranch()->findOrFail($id);
        abort_if($entry->isCancelled(), 403);

        RecordStockInPayment::markPaid($entry, Auth::id());

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->inCurrentBranch()->findOrFail($id);
        abort_if($entry->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->inCurrentBranch()->findOrFail($this->payingId);
        abort_if($entry->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        [$paymentMethod, $shopAccountId] = $this->paidFromParts();
        $this->paidFrom = '';

        RecordStockInPayment::add($entry, (float) $this->paymentAmount, Auth::id(), paymentMethod: $paymentMethod, shopAccountId: $shopAccountId);

        $this->toastSuccess('Payment recorded.');
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }


    public function closePaymentForm(): void
    {
        $this->dispatch('close-modal', name: 'add-payment-form');
        $this->reset(['payingId', 'paymentAmount']);
    }

    public function openEdit(int $entryId): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $entry = StockIn::inCurrentBranch()->findOrFail($entryId);

        $this->editingId = $entry->id;
        $this->editQuantity = (string) $entry->quantity;
        $this->editUnit = $entry->unit->value;
        $this->editFromGrn = $entry->grn_line_id !== null;
        $this->editCostLocked = $this->editFromGrn && VendorInvoices::holdsCost($entry);
        $this->editVendorId = $entry->vendor_id !== null ? (string) $entry->vendor_id : '';
        $this->editStockDate = $entry->stock_date->toDateString();
        $this->editNote = $entry->note ?? '';
        $this->editCostPrice = $entry->cost_price !== null ? (string) $entry->cost_price : '';
        $this->editSellingPrice = $entry->selling_price !== null ? (string) $entry->selling_price : '';
        $this->editExpiryDate = $entry->expiry_date?->toDateString() ?? '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-stock-in-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $entry = StockIn::inCurrentBranch()->findOrFail($this->editingId);

        $this->validate([
            'editQuantity' => $entry->unit->quantityRules(),
            'editVendorId' => ['nullable', 'integer'],
            'editStockDate' => ['required', 'date', 'before_or_equal:today'],
            'editNote' => ['nullable', 'string', 'max:255'],
            'editCostPrice' => ['nullable', 'numeric', 'min:0'],
            'editSellingPrice' => ['nullable', 'numeric', 'min:0'],
            'editExpiryDate' => ['nullable', 'date'],
        ]);

        UpdateStockIn::handle($entry, [
            'quantity' => $this->editQuantity,
            'vendor_id' => $this->editVendorId !== '' ? (int) $this->editVendorId : null,
            'stock_date' => $this->editStockDate,
            'note' => $this->editNote !== '' ? $this->editNote : null,
            'cost_price' => $this->editCostPrice !== '' ? (float) $this->editCostPrice : null,
            'selling_price' => $this->editSellingPrice !== '' ? (float) $this->editSellingPrice : null,
            ...($this->expiryEnabled() ? ['expiry_date' => $this->editExpiryDate !== '' ? $this->editExpiryDate : null] : []),
        ], Auth::id());

        $this->toastSuccess('Stock-in entry updated.');
        $this->dispatch('close-modal', name: 'edit-stock-in-form');
        $this->reset(['editingId', 'editQuantity', 'editUnit', 'editFromGrn', 'editCostLocked', 'editVendorId', 'editStockDate', 'editNote', 'editCostPrice', 'editSellingPrice', 'editExpiryDate']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-stock-in-form');
        $this->reset(['editingId', 'editQuantity', 'editUnit', 'editFromGrn', 'editCostLocked', 'editVendorId', 'editStockDate', 'editNote', 'editCostPrice', 'editSellingPrice', 'editExpiryDate']);
    }

    public function openCancel(int $entryId): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->cancelingId = $entryId;
        $this->cancelReason = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'cancel-stock-in-form');
    }

    public function cancelEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->resetErrorBag();

        $entry = StockIn::inCurrentBranch()->findOrFail($this->cancelingId);
        $result = CancelStockIn::handle($entry, Auth::id(), $this->cancelReason);

        $this->dispatch('close-modal', name: 'cancel-stock-in-form');
        $this->reset(['cancelingId', 'cancelReason']);

        $message = $result['reversed']
            ? 'Stock-in entry cancelled and stock reduced back.'
            : 'Stock-in entry cancelled. Could not reduce stock (product deleted).';

        $this->toastSuccess($message);
    }

    public function closeCancelForm(): void
    {
        $this->dispatch('close-modal', name: 'cancel-stock-in-form');
        $this->reset(['cancelingId', 'cancelReason']);
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header>
            Stock In History
            <x-slot:actions>
                <x-ui.button href="{{ route('stock-ins.index') }}" wire:navigate size="sm">Add Stock</x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    <x-ui.module-tabs module="stock" label="Stock In sections" />

    <x-ui.filter-bar :active="(int) filled($from) + (int) filled($to) + (int) filled($productFilter) + (int) filled($paymentStatusFilter) + (int) filled($vendorFilter)">
        <x-ui.date-range />

        <x-ui.field label="Product" name="productFilter" for="productFilter" class="w-full sm:w-52">
            <x-ui.select wire:model.live="productFilter" id="productFilter">
                <option value="">All Products</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="w-full sm:w-40">
            <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                <option value="">All Statuses</option>
                @foreach ($paymentStatuses as $status)
                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                @endforeach
            </x-ui.select>
        </x-ui.field>

        @if (auth()->user()->hasAccessTo('vendors'))
            <x-ui.field label="Vendor" name="vendorFilter" for="vendorFilter" class="w-full sm:w-44">
                <x-ui.select wire:model.live="vendorFilter" id="vendorFilter">
                    <option value="">All Vendors</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>
        @endif

        @if ($from || $to || $productFilter || $paymentStatusFilter || $vendorFilter)
            <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                Clear
            </x-ui.button>
        @endif

        <x-slot:actions>
            <x-ui.export-dropdown />
        </x-slot:actions>
    </x-ui.filter-bar>

    <x-ui.stat-row>
        <x-ui.stat label="Total Units" :value="number_format($totalUnits)" />
        @if ($measuredTotals !== '')
            <x-ui.stat label="Weight, Volume &amp; Length" :value="$measuredTotals" />
        @endif
        <x-ui.stat label="Total Owed to Suppliers" :value="'Rs '.number_format($totalOwed, 2)" />
    </x-ui.stat-row>

    @if ($entries->isEmpty())
        <x-ui.empty-state
            title="No stock-in entries yet"
            description="Entries logged from the Stock In screen will show up here."
        >
            <x-slot name="action">
                <x-ui.button href="{{ route('stock-ins.index') }}" wire:navigate>Add Stock</x-ui.button>
            </x-slot>
        </x-ui.empty-state>
    @else
        {{-- Twelve facts in seven columns: the quieter ones sit under the main
             value of their cell (vendor and note under product, who entered it
             under date, what's left under quantity, what's owed under payment). --}}
        @php $showVendorColumn = auth()->user()->hasAccessTo('vendors'); @endphp
        <x-ui.table stack :headers="['Product', 'Date', 'Quantity', ...($showExpiry ? ['Expires'] : []), 'Cost / Sell', 'Payment', '']">
            @foreach ($entries as $entry)
                <x-ui.table-row wire:key="stock-in-{{ $entry->id }}" @class(['opacity-50' => $entry->isCancelled()])>
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        {{ $entry->product_name }}
                        @if ($showVendorColumn)
                            <span class="ui-sub">{{ $entry->vendor?->name ?? '—' }}</span>
                        @endif
                        @if ($entry->note)
                            <span class="ui-sub max-w-[16rem] truncate italic" title="{{ $entry->note }}">{{ $entry->note }}</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Date">
                        {{ $entry->stock_date->format('d M Y') }}
                        <span class="ui-sub">{{ $entry->user?->name ?? '—' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Quantity">
                        <span class="font-semibold text-success">+{{ $entry->unit->groupedAmount($entry->quantity) }}</span>
                        @unless ($entry->isCancelled())
                            <span class="ui-sub">{{ $entry->unit->groupedAmount($entry->quantity_remaining) }} left</span>
                        @endunless
                    </x-ui.table-cell>
                    @if ($showExpiry)
                        <x-ui.table-cell label="Expires"><span @class(['font-semibold text-danger' => $entry->isExpired() && $entry->quantity_remaining > 0])>{{ $entry->expiry_date?->format('d M Y') ?? '—' }}</span></x-ui.table-cell>
                    @endif
                    <x-ui.table-cell label="Cost / Sell">
                        {{ $entry->cost_price !== null ? 'Rs '.number_format($entry->cost_price, 2) : '—' }}
                        <span class="ui-sub">sells {{ $entry->selling_price !== null ? 'Rs '.number_format($entry->selling_price, 2) : '—' }}</span>
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Payment">
                        @if ($entry->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                        @if (! $entry->isCancelled() && $entry->amountOwed() > 0)
                            <span class="ui-sub font-semibold text-ink">Rs {{ number_format($entry->amountOwed(), 2) }} owed</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        <x-ui.row-actions label="Actions for {{ $entry->product_name }}">
                            @if (! $entry->isCancelled())
                                @if ($entry->payment_status->value !== 'paid')
                                    <x-ui.menu-item wire:click="openAddPayment({{ $entry->id }})">Add Payment</x-ui.menu-item>
                                    <x-ui.menu-item wire:click="markAsPaid({{ $entry->id }})" confirm="Mark this stock-in as fully paid?" confirm-title="Mark as paid?" confirm-label="Mark as Paid">Mark as Paid</x-ui.menu-item>
                                @endif
                                @if (auth()->user()->hasAccessTo('stock-ins-edit-cancel'))
                                    @if ($entry->quantity_remaining > 0 && $entry->product_id !== null)
                                        <x-ui.menu-item href="{{ route('stock-ins.write-offs', ['batch' => $entry->id]) }}" wire:navigate>Write off</x-ui.menu-item>
                                    @endif
                                    <x-ui.menu-item wire:click="openEdit({{ $entry->id }})">Edit</x-ui.menu-item>
                                    @if ($entry->grn_line_id === null)
                                        <x-ui.menu-divider />
                                        <x-ui.menu-item danger wire:click="openCancel({{ $entry->id }})">Delete</x-ui.menu-item>
                                    @endif
                                @endif
                            @endif
                        </x-ui.row-actions>
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>

        <div class="mt-4">
            {{ $entries->links() }}
        </div>
    @endif

    <x-ui.modal name="add-payment-form" max-width="sm">
        <form wire:submit="submitPayment" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Add Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="paymentAmount" for="paymentAmount">
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
                <x-ledger.paid-from :value="$paidFrom" />
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closePaymentForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="submitPayment">
                    Save Payment
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="edit-stock-in-form" max-width="sm">
        <form wire:submit="saveEdit" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Edit Stock-In Entry</h2>
            <p class="mt-2 text-sm text-ink-muted">The product on this entry cannot be changed. Changing the quantity adjusts the product's current stock by the difference.</p>

            <div class="mt-5 space-y-5">
                @php($editUnitCase = QuantityUnit::fromValue($editUnit))
                <x-ui.field :label="$editUnitCase->allowsDecimals() ? 'Quantity ('.$editUnitCase->symbol().')' : 'Quantity'" name="editQuantity" for="editQuantity">
                    <x-ui.input wire:model="editQuantity" id="editQuantity" type="number" :min="$editUnitCase->inputMin()" :step="$editUnitCase->inputStep()" :disabled="$editFromGrn" />
                    <x-ui.error :messages="$errors->get('quantity')" class="mt-1" />
                </x-ui.field>

                @if ($editFromGrn)
                    <p class="text-sm text-ink-faint">This stock came from a goods receipt, so its quantity and vendor can't be changed here. Cancel the receipt and record it again.</p>
                @endif

                @if ($editFromGrn && auth()->user()->hasAccessTo('vendors'))
                    <x-ui.field label="Vendor" name="editVendorId" for="editVendorId">
                        <x-ui.input id="editVendorId" :value="$vendors->firstWhere('id', (int) $editVendorId)?->name ?? 'No vendor'" disabled />
                    </x-ui.field>
                @elseif (auth()->user()->hasAccessTo('vendors'))
                    <x-ui.field label="Vendor" name="editVendorId" for="editVendorId" help="Optional">
                        <x-ui.select wire:model="editVendorId" id="editVendorId">
                            <option value="">No vendor</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </x-ui.field>
                @endif

                <x-ui.field label="Date Received" name="editStockDate" for="editStockDate">
                    <x-ui.input wire:model="editStockDate" id="editStockDate" type="date" max="{{ now()->toDateString() }}" />
                </x-ui.field>

                @if ($expiryEnabled)
                    <x-ui.field label="Expiry Date" name="editExpiryDate" for="editExpiryDate" help="Optional. The last day it can be sold">
                        <x-ui.input wire:model="editExpiryDate" id="editExpiryDate" type="date" />
                    </x-ui.field>
                @endif

                <x-ui.field label="Note" name="editNote" for="editNote" help="Optional">
                    <x-ui.input wire:model="editNote" id="editNote" />
                </x-ui.field>

                <x-ui.field label="Cost Price (per unit)" name="editCostPrice" for="editCostPrice" :help="$editCostLocked ? 'An approved vendor invoice set this cost. Cancel the invoice to change it.' : 'Optional'">
                    <x-ui.input wire:model="editCostPrice" id="editCostPrice" type="number" min="0" step="0.01" :disabled="$editCostLocked" />
                </x-ui.field>

                <x-ui.field label="Selling Price (per unit)" name="editSellingPrice" for="editSellingPrice" help="Optional">
                    <x-ui.input wire:model="editSellingPrice" id="editSellingPrice" type="number" min="0" step="0.01" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeEditForm">
                    Cancel
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="saveEdit">
                    Save Changes
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <x-ui.modal name="cancel-stock-in-form" max-width="sm">
        <form wire:submit="cancelEntry" class="p-6">
            <h2 class="text-lg font-semibold text-ink">Cancel This Stock-In Entry?</h2>
            <p class="mt-2 text-sm text-ink-muted">
                This entry will be marked <strong>Cancelled</strong> and its quantity will be removed from the product's current stock. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <x-ui.error :messages="$errors->get('quantity')" class="mt-3" />

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Entry
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelEntry" variant="danger">
                    Cancel Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
