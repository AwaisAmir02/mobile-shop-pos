<?php

use App\Actions\CancelStockIn;
use App\Actions\UpdateStockIn;
use App\Enums\PaymentStatus;
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
    use GuardsExportSize, Toasts, WithPagination;

    public string $from = '';
    public string $to = '';
    public string $productFilter = '';
    public string $paymentStatusFilter = '';

    #[Url]
    public string $vendorFilter = '';

    public ?int $payingId = null;
    public string $paymentAmount = '';

    public ?int $editingId = null;
    public string $editQuantity = '';
    public string $editVendorId = '';
    public string $editStockDate = '';
    public string $editNote = '';
    public string $editCostPrice = '';
    public string $editSellingPrice = '';

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
            'totalOwed' => $report->totalOwed(),
            'products' => Product::query()->orderBy('name')->get(),
            'paymentStatuses' => PaymentStatus::cases(),
            'vendors' => Auth::user()->hasAccessTo('vendors') ? Vendor::query()->orderBy('name')->get() : collect(),
        ];
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
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($entry->isCancelled(), 403);

        $entry->update([
            'payment_status' => PaymentStatus::Paid,
            'amount_paid' => $entry->total_cost ?? $entry->amount_paid,
        ]);

        $this->toastSuccess('Marked as paid.');
    }

    public function openAddPayment(int $id): void
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($id);
        abort_if($entry->isCancelled(), 403);

        $this->payingId = $id;
        $this->paymentAmount = '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'add-payment-form');
    }

    public function submitPayment(): void
    {
        $entry = StockIn::withoutGlobalScope(ExcludesCancelledScope::class)->findOrFail($this->payingId);
        abort_if($entry->isCancelled(), 403);

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $newAmountPaid = (float) $entry->amount_paid + (float) $this->paymentAmount;

        $entry->update([
            'amount_paid' => $newAmountPaid,
            'payment_status' => $entry->total_cost !== null && $newAmountPaid >= (float) $entry->total_cost
                ? PaymentStatus::Paid
                : PaymentStatus::Partial,
        ]);

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

        $entry = StockIn::findOrFail($entryId);

        $this->editingId = $entry->id;
        $this->editQuantity = (string) $entry->quantity;
        $this->editVendorId = $entry->vendor_id !== null ? (string) $entry->vendor_id : '';
        $this->editStockDate = $entry->stock_date->toDateString();
        $this->editNote = $entry->note ?? '';
        $this->editCostPrice = $entry->cost_price !== null ? (string) $entry->cost_price : '';
        $this->editSellingPrice = $entry->selling_price !== null ? (string) $entry->selling_price : '';
        $this->resetErrorBag();
        $this->dispatch('open-modal', name: 'edit-stock-in-form');
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->validate([
            'editQuantity' => ['required', 'integer', 'min:1'],
            'editVendorId' => ['nullable', 'integer'],
            'editStockDate' => ['required', 'date', 'before_or_equal:today'],
            'editNote' => ['nullable', 'string', 'max:255'],
            'editCostPrice' => ['nullable', 'numeric', 'min:0'],
            'editSellingPrice' => ['nullable', 'numeric', 'min:0'],
        ]);

        $entry = StockIn::findOrFail($this->editingId);

        UpdateStockIn::handle($entry, [
            'quantity' => (int) $this->editQuantity,
            'vendor_id' => $this->editVendorId !== '' ? (int) $this->editVendorId : null,
            'stock_date' => $this->editStockDate,
            'note' => $this->editNote !== '' ? $this->editNote : null,
            'cost_price' => $this->editCostPrice !== '' ? (float) $this->editCostPrice : null,
            'selling_price' => $this->editSellingPrice !== '' ? (float) $this->editSellingPrice : null,
        ], Auth::id());

        $this->toastSuccess('Stock-in entry updated.');
        $this->dispatch('close-modal', name: 'edit-stock-in-form');
        $this->reset(['editingId', 'editQuantity', 'editVendorId', 'editStockDate', 'editNote', 'editCostPrice', 'editSellingPrice']);
    }

    public function closeEditForm(): void
    {
        $this->dispatch('close-modal', name: 'edit-stock-in-form');
        $this->reset(['editingId', 'editQuantity', 'editVendorId', 'editStockDate', 'editNote', 'editCostPrice', 'editSellingPrice']);
    }

    public function openCancel(int $entryId): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $this->cancelingId = $entryId;
        $this->cancelReason = '';
        $this->dispatch('open-modal', name: 'cancel-stock-in-form');
    }

    public function cancelEntry(): void
    {
        abort_unless(Auth::user()->hasAccessTo('stock-ins-edit-cancel'), 403);

        $entry = StockIn::findOrFail($this->cancelingId);
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
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Stock In History</h1>
            <a href="{{ route('stock-ins.index') }}" wire:navigate>
                <x-ui.button size="sm">Add Stock</x-ui.button>
            </a>
        </div>
    </x-slot>

    <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:flex-wrap">
            <x-ui.field label="From" name="from" for="from" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="from" id="from" type="date" />
            </x-ui.field>

            <x-ui.field label="To" name="to" for="to" class="sm:max-w-[10rem]">
                <x-ui.input wire:model.live="to" id="to" type="date" />
            </x-ui.field>

            <x-ui.field label="Product" name="productFilter" for="productFilter" class="sm:max-w-[14rem]">
                <x-ui.select wire:model.live="productFilter" id="productFilter">
                    <option value="">All Products</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            <x-ui.field label="Payment Status" name="paymentStatusFilter" for="paymentStatusFilter" class="sm:max-w-[10rem]">
                <x-ui.select wire:model.live="paymentStatusFilter" id="paymentStatusFilter">
                    <option value="">All Statuses</option>
                    @foreach ($paymentStatuses as $status)
                        <option value="{{ $status->value }}">{{ $status->label() }}</option>
                    @endforeach
                </x-ui.select>
            </x-ui.field>

            @if (auth()->user()->hasAccessTo('vendors'))
                <x-ui.field label="Vendor" name="vendorFilter" for="vendorFilter" class="sm:max-w-[12rem]">
                    <x-ui.select wire:model.live="vendorFilter" id="vendorFilter">
                        <option value="">All Vendors</option>
                        @foreach ($vendors as $vendor)
                            <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                        @endforeach
                    </x-ui.select>
                </x-ui.field>
            @endif

            @if ($from || $to || $productFilter || $paymentStatusFilter || $vendorFilter)
                <div class="sm:pb-1">
                    <x-ui.button type="button" variant="ghost" wire:click="clearFilters">
                        Clear
                    </x-ui.button>
                </div>
            @endif
        </div>

        <div class="flex items-center gap-4">
            <x-ui.export-dropdown />

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Units</p>
                    <p class="text-display-sm text-slate-900">{{ number_format($totalUnits) }}</p>
                </div>
            </x-ui.card>

            <x-ui.card :padding="false">
                <div class="px-5 py-3 text-right">
                    <p class="text-xs font-medium uppercase tracking-wide text-slate-400">Total Owed to Suppliers</p>
                    <p class="text-display-sm text-slate-900">Rs {{ number_format($totalOwed, 2) }}</p>
                </div>
            </x-ui.card>
        </div>
    </div>

    @if ($entries->isEmpty())
        <x-ui.empty-state
            title="No stock-in entries yet"
            description="Entries logged from the Stock In screen will show up here."
        >
            <x-slot name="action">
                <a href="{{ route('stock-ins.index') }}" wire:navigate>
                    <x-ui.button>Add Stock</x-ui.button>
                </a>
            </x-slot>
        </x-ui.empty-state>
    @else
        @php $showVendorColumn = auth()->user()->hasAccessTo('vendors'); @endphp
        <x-ui.table :headers="$showVendorColumn ? ['Date', 'Product', 'Vendor', 'Quantity', 'Remaining', 'Cost / Sell', 'Payment', 'Owed', 'Note', 'Entered By', ''] : ['Date', 'Product', 'Quantity', 'Remaining', 'Cost / Sell', 'Payment', 'Owed', 'Note', 'Entered By', '']">
            @foreach ($entries as $entry)
                <x-ui.table-row wire:key="stock-in-{{ $entry->id }}" @class(['opacity-50' => $entry->isCancelled()])>
                    <x-ui.table-cell>{{ $entry->stock_date->format('d M Y') }}</x-ui.table-cell>
                    <x-ui.table-cell class="font-medium text-slate-900">{{ $entry->product_name }}</x-ui.table-cell>
                    @if ($showVendorColumn)
                        <x-ui.table-cell>{{ $entry->vendor?->name ?? '—' }}</x-ui.table-cell>
                    @endif
                    <x-ui.table-cell class="font-semibold text-emerald-600">+{{ number_format($entry->quantity) }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->isCancelled() ? '—' : number_format($entry->quantity_remaining) }}</x-ui.table-cell>
                    <x-ui.table-cell>
                        {{ $entry->cost_price !== null ? 'Rs '.number_format($entry->cost_price, 2) : '—' }}
                        /
                        {{ $entry->selling_price !== null ? 'Rs '.number_format($entry->selling_price, 2) : '—' }}
                    </x-ui.table-cell>
                    <x-ui.table-cell>
                        @if ($entry->isCancelled())
                            <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'paid')
                            <x-ui.badge variant="success">Paid</x-ui.badge>
                        @elseif ($entry->payment_status->value === 'partial')
                            <x-ui.badge variant="warning">Partial</x-ui.badge>
                        @else
                            <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell class="font-semibold text-slate-900">
                        @if (! $entry->isCancelled() && $entry->amountOwed() > 0)
                            Rs {{ number_format($entry->amountOwed(), 2) }}
                        @else
                            —
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->note ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell>{{ $entry->user?->name ?? '—' }}</x-ui.table-cell>
                    <x-ui.table-cell align="right">
                        <div class="flex justify-end gap-2">
                            @if (! $entry->isCancelled())
                                @if ($entry->payment_status->value !== 'paid')
                                    <x-ui.button size="sm" variant="ghost" wire:click="openAddPayment({{ $entry->id }})">
                                        Add Payment
                                    </x-ui.button>
                                    <x-ui.button
                                        size="sm"
                                        variant="ghost"
                                        wire:click="markAsPaid({{ $entry->id }})"
                                        wire:confirm="Mark this stock-in as fully paid?"
                                    >
                                        Mark as Paid
                                    </x-ui.button>
                                @endif
                                @if (auth()->user()->hasAccessTo('stock-ins-edit-cancel'))
                                    <x-ui.button size="sm" variant="ghost" wire:click="openEdit({{ $entry->id }})">
                                        Edit
                                    </x-ui.button>
                                    <x-ui.button size="sm" variant="ghost" wire:click="openCancel({{ $entry->id }})" class="text-red-600 hover:bg-red-50">
                                        Delete
                                    </x-ui.button>
                                @endif
                            @endif
                        </div>
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
            <h2 class="text-lg font-semibold text-slate-900">Add Payment</h2>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Amount Paid Now" name="paymentAmount" for="paymentAmount">
                    <x-ui.input wire:model="paymentAmount" id="paymentAmount" type="number" min="0.01" step="0.01" autofocus />
                </x-ui.field>
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
            <h2 class="text-lg font-semibold text-slate-900">Edit Stock-In Entry</h2>
            <p class="mt-2 text-sm text-slate-600">The product on this entry cannot be changed. Changing the quantity adjusts the product's current stock by the difference.</p>

            <div class="mt-5 space-y-5">
                <x-ui.field label="Quantity" name="editQuantity" for="editQuantity">
                    <x-ui.input wire:model="editQuantity" id="editQuantity" type="number" min="1" step="1" />
                </x-ui.field>

                @if (auth()->user()->hasAccessTo('vendors'))
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

                <x-ui.field label="Note" name="editNote" for="editNote" help="Optional">
                    <x-ui.input wire:model="editNote" id="editNote" />
                </x-ui.field>

                <x-ui.field label="Cost Price (per unit)" name="editCostPrice" for="editCostPrice" help="Optional">
                    <x-ui.input wire:model="editCostPrice" id="editCostPrice" type="number" min="0" step="0.01" />
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
            <h2 class="text-lg font-semibold text-slate-900">Cancel This Stock-In Entry?</h2>
            <p class="mt-2 text-sm text-slate-600">
                This entry will be marked <strong>Cancelled</strong> and its quantity will be removed from the product's current stock. It will no longer count toward any total, report, or outstanding balance. This cannot be undone.
            </p>

            <div class="mt-5">
                <x-ui.field label="Reason" name="cancelReason" for="cancelReason" help="Optional">
                    <x-ui.input wire:model="cancelReason" id="cancelReason" />
                </x-ui.field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <x-ui.button type="button" variant="secondary" wire:click="closeCancelForm">
                    Keep Entry
                </x-ui.button>

                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="cancelEntry" class="bg-red-600 hover:bg-red-700 focus:ring-red-500">
                    Cancel Entry
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
