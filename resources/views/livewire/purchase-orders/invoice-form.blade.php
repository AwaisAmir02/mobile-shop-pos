<?php

use App\Actions\VendorInvoices;
use App\Models\Scopes\ExcludesCancelledScope;
use App\Models\StockIn;
use App\Models\Vendor;
use App\Models\VendorInvoiceLine;
use App\Support\BatchExpiry;
use App\Support\Quantity;
use App\Support\VendorInvoiceMatch;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Record Vendor Invoice')] class extends Component
{
    #[Url]
    public string $vendorId = '';

    public string $invoiceNumber = '';
    public string $invoiceDate = '';
    public string $note = '';

    /** @var array<int, array{quantity: string, amount: string}> grn_line_id => what was billed; only ticked lines are here */
    public array $billed = [];

    /** @var array<int, array{label: string, amount: string}> */
    public array $charges = [];
    public int $nextCharge = 1;

    public function boot(): void
    {
        abort_unless(Auth::user()->hasAccessTo('vendor-invoices'), 403);
    }

    public function mount(): void
    {
        $this->invoiceDate = BatchExpiry::today();
    }

    public function updatedVendorId(): void
    {
        $this->billed = [];
    }

    /** Received from this vendor, not cancelled, and not on another active invoice. */
    protected function eligibleRows()
    {
        if ($this->vendorId === '') {
            return collect();
        }

        return StockIn::query()
            ->inCurrentBranch()
            ->where('vendor_id', (int) $this->vendorId)
            ->whereNotNull('grn_line_id')
            ->whereDoesntHave('activeInvoiceLine')
            ->with(['grnLine.poLine', 'grnLine.grn', 'branch'])
            ->orderBy('stock_date')
            ->orderBy('id')
            ->get();
    }

    public function toggleLine(int $grnLineId): void
    {
        if (isset($this->billed[$grnLineId])) {
            unset($this->billed[$grnLineId]);

            return;
        }

        $row = $this->eligibleRows()->firstWhere('grn_line_id', $grnLineId);
        abort_if($row === null, 404);

        $this->billed[$grnLineId] = $this->prefill($row);
    }

    public function selectAll(): void
    {
        foreach ($this->eligibleRows() as $row) {
            $this->billed[$row->grn_line_id] ??= $this->prefill($row);
        }
    }

    /** Starts at what was received, so only a real difference needs typing. */
    protected function prefill(StockIn $row): array
    {
        return ['quantity' => (string) Quantity::plain($row->quantity), 'amount' => (string) ($row->total_cost ?? '')];
    }

    public function addCharge(): void
    {
        $this->charges[$this->nextCharge++] = ['label' => '', 'amount' => ''];
    }

    public function removeCharge(int $key): void
    {
        unset($this->charges[$key]);
    }

    public function with(): array
    {
        $rows = $this->eligibleRows();
        $preview = null;

        if ($this->billed !== []) {
            $lines = $rows->filter(fn (StockIn $row) => isset($this->billed[$row->grn_line_id]))->map(function (StockIn $row) {
                $input = $this->billed[$row->grn_line_id];
                $line = new VendorInvoiceLine([
                    'grn_line_id' => $row->grn_line_id,
                    'stock_in_id' => $row->id,
                    'billed_quantity' => is_numeric($input['quantity']) && (float) $input['quantity'] > 0 ? $input['quantity'] : 0,
                    'billed_amount' => is_numeric($input['amount']) ? $input['amount'] : 0,
                ]);
                $line->setRelation('stockIn', $row);
                $line->setRelation('grnLine', $row->grnLine);

                return $line;
            });

            $preview = VendorInvoiceMatch::evaluate($lines, collect($this->charges)->filter(fn ($charge) => is_numeric($charge['amount']))->all());
            $preview['by_grn_line'] = collect($preview['lines'])->keyBy(fn (array $entry) => $entry['line']->grn_line_id);
            $preview['billed_total'] = round($lines->sum(fn ($line) => (float) $line->billed_amount) + collect($this->charges)->sum(fn ($charge) => is_numeric($charge['amount']) ? (float) $charge['amount'] : 0), 2);
        }

        return [
            'vendors' => Vendor::query()->orderBy('name')->get(),
            'rows' => $rows,
            'preview' => $preview,
            'enabled' => (bool) Auth::user()->shop?->purchase_orders_enabled,
        ];
    }

    public function save(): void
    {
        $this->validate([
            'vendorId' => ['required', 'integer', Rule::exists('vendors', 'id')->where('shop_id', Auth::user()->shop_id)],
            'invoiceNumber' => ['required', 'string', 'max:100'],
            'invoiceDate' => ['required', 'date', 'before_or_equal:'.BatchExpiry::today()],
            'note' => ['nullable', 'string', 'max:500'],
            'billed' => ['required', 'array', 'min:1'],
            'billed.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'billed.*.amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'charges.*.label' => ['required', 'string', 'max:100'],
            'charges.*.amount' => ['required', 'numeric', 'min:-99999999.99', 'max:99999999.99', 'not_in:0'],
        ], [
            'billed.required' => 'Tick at least one received item.',
            'billed.*.quantity.*' => 'Enter the billed quantity for each ticked item.',
            'billed.*.amount.*' => 'Enter the billed amount for each ticked item.',
            'charges.*.label.required' => 'Name each extra charge, like Delivery or Discount.',
            'charges.*.amount.*' => 'Enter an amount for each extra charge. Use a minus for a discount.',
        ], ['vendorId' => 'vendor', 'invoiceNumber' => 'invoice number', 'invoiceDate' => 'invoice date']);

        $invoice = VendorInvoices::record(
            ['vendor_id' => (int) $this->vendorId, 'invoice_number' => $this->invoiceNumber, 'invoice_date' => $this->invoiceDate, 'note' => $this->note],
            collect($this->billed)->map(fn (array $input, int $grnLineId) => ['grn_line_id' => $grnLineId, 'billed_quantity' => $input['quantity'], 'billed_amount' => $input['amount']])->values()->all(),
            array_values($this->charges),
            Auth::id(),
        );

        session()->flash('status', $invoice->isApproved() ? 'Invoice matched within Rs 1, so it was approved. Nothing changed in what you owe.' : 'Invoice saved. It has differences that need review.');

        $this->redirectRoute('purchase-orders.invoices.show', $invoice->id, navigate: true);
    }
}; ?>

<div>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-semibold text-slate-900">Record Vendor Invoice</h1>
            <a href="{{ route('purchase-orders.invoices') }}" wire:navigate class="text-sm font-medium text-brand-700 hover:text-brand-800">← Vendor Invoices</a>
        </div>
    </x-slot>

    @unless ($enabled)
        <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Purchase orders aren't on your plan right now, so new invoices can't be recorded.
        </div>
    @endunless

    <form wire:submit="save" class="space-y-6">
        <x-ui.card>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-ui.field label="Vendor" name="vendorId" for="invoiceVendor">
                    <div wire:key="invoice-vendor-select">
                        <x-ui.select wire:model.live="vendorId" id="invoiceVendor">
                            <option value="">Choose a vendor</option>
                            @foreach ($vendors as $vendor)
                                <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                            @endforeach
                        </x-ui.select>
                    </div>
                </x-ui.field>
                <x-ui.field label="Vendor's Invoice No." name="invoiceNumber" for="invoiceNumber">
                    <x-ui.input wire:model="invoiceNumber" id="invoiceNumber" maxlength="100" />
                </x-ui.field>
                <x-ui.field label="Invoice Date" name="invoiceDate" for="invoiceDate">
                    <x-ui.input wire:model="invoiceDate" id="invoiceDate" type="date" />
                </x-ui.field>
            </div>
            <div class="mt-4">
                <x-ui.field label="Note" name="note" for="invoiceNote" help="Optional">
                    <x-ui.input wire:model="note" id="invoiceNote" />
                </x-ui.field>
            </div>
        </x-ui.card>

        <x-ui.error :messages="$errors->get('lines')" />
        <x-ui.error :messages="$errors->get('billed')" />

        @if ($vendorId === '')
            <x-ui.empty-state title="Choose a vendor" description="Their received goods that aren't on an invoice yet will show here." />
        @elseif ($rows->isEmpty())
            <x-ui.empty-state title="Nothing to bill" description="Everything received from this vendor is already on an invoice, or nothing has been received yet." />
        @else
            <div>
                <div class="mb-2 flex items-center justify-between gap-3">
                    <h2 class="text-sm font-semibold text-slate-900">Received goods</h2>
                    <x-ui.button type="button" size="sm" variant="ghost" wire:click="selectAll">Select All</x-ui.button>
                </div>
                <x-ui.table :headers="['', 'Product', 'Receipt', 'Ordered', 'Received', 'Billed Qty', 'Billed Amount', 'Difference']">
                    @foreach ($rows as $row)
                        @php
                            $ticked = isset($billed[$row->grn_line_id]);
                            $entry = $preview['by_grn_line'][$row->grn_line_id] ?? null;
                            $poLine = $row->grnLine?->poLine;
                        @endphp
                        <x-ui.table-row wire:key="invoice-row-{{ $row->grn_line_id }}">
                            <x-ui.table-cell>
                                <input type="checkbox" wire:click="toggleLine({{ $row->grn_line_id }})" @checked($ticked) aria-label="Include {{ $row->product_name }}" class="h-4 w-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                            </x-ui.table-cell>
                            <x-ui.table-cell class="font-medium text-slate-900">{{ $row->product_name }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $row->grnLine?->grn?->number() ?? '—' }}<p class="text-xs text-slate-400">{{ $row->stock_date->format('d M Y') }}</p></x-ui.table-cell>
                            <x-ui.table-cell>
                                {{ $poLine ? $poLine->unit->amount($poLine->quantity) : '—' }}
                                @if ($poLine?->expected_cost !== null)
                                    <p class="text-xs text-slate-400">at Rs {{ number_format($poLine->expected_cost, 2) }}</p>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell>
                                {{ $row->unit->amount($row->quantity) }}
                                <p class="text-xs text-slate-400">{{ $row->total_cost !== null ? 'Rs '.number_format($row->total_cost, 2) : 'No cost' }}</p>
                            </x-ui.table-cell>
                            @if ($ticked)
                                <x-ui.table-cell>
                                    <x-ui.input wire:model.live.debounce.400ms="billed.{{ $row->grn_line_id }}.quantity" type="number" min="0" :step="$row->unit->inputStep()" aria-label="Billed quantity for {{ $row->product_name }}" class="w-24" />
                                </x-ui.table-cell>
                                <x-ui.table-cell>
                                    <x-ui.input wire:model.live.debounce.400ms="billed.{{ $row->grn_line_id }}.amount" type="number" min="0" step="0.01" aria-label="Billed amount for {{ $row->product_name }}" class="w-32" />
                                </x-ui.table-cell>
                                <x-ui.table-cell>
                                    @if ($entry && ($entry['quantity_differs'] || $entry['difference'] > 0))
                                        <span class="font-semibold text-amber-700">{{ $entry['quantity_differs'] ? 'Quantity' : 'Rs '.number_format($entry['difference'], 2) }}</span>
                                    @else
                                        <span class="text-emerald-700">Matches</span>
                                    @endif
                                </x-ui.table-cell>
                            @else
                                <x-ui.table-cell>—</x-ui.table-cell>
                                <x-ui.table-cell>—</x-ui.table-cell>
                                <x-ui.table-cell>—</x-ui.table-cell>
                            @endif
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            </div>

            <x-ui.card title="Delivery, round-off and discounts" description="Anything on the bill not tied to one product. It's shared across the lines by value. Use a minus for a discount.">
                <div class="space-y-3">
                    @foreach ($charges as $key => $charge)
                        <div class="grid grid-cols-[minmax(0,1fr)_8rem_auto] items-start gap-2" wire:key="invoice-charge-{{ $key }}">
                            <div>
                                <x-ui.input wire:model="charges.{{ $key }}.label" placeholder="Delivery" aria-label="Charge name" />
                                <x-ui.error :messages="$errors->get('charges.'.$key.'.label')" class="mt-1" />
                            </div>
                            <div>
                                <x-ui.input wire:model.live.debounce.400ms="charges.{{ $key }}.amount" type="number" step="0.01" aria-label="Charge amount" />
                                <x-ui.error :messages="$errors->get('charges.'.$key.'.amount')" class="mt-1" />
                            </div>
                            <x-ui.button type="button" size="sm" variant="ghost" wire:click="removeCharge({{ $key }})">Remove</x-ui.button>
                        </div>
                    @endforeach
                </div>
                <x-ui.button type="button" size="sm" variant="secondary" wire:click="addCharge" class="mt-3">Add Charge</x-ui.button>
            </x-ui.card>

            @if ($preview)
                <div @class([
                    'rounded-xl border px-4 py-3 text-sm',
                    'border-emerald-200 bg-emerald-50 text-emerald-800' => $preview['passes'],
                    'border-amber-200 bg-amber-50 text-amber-800' => ! $preview['passes'],
                ])>
                    <p class="font-semibold">Billed total: Rs {{ number_format($preview['billed_total'], 2) }}</p>
                    @if ($preview['passes'])
                        <p class="mt-1">Matches within Rs 1, so it will be approved when you save. What you owe won't change.</p>
                    @else
                        <p class="mt-1">This will need review before anything changes:</p>
                        <ul class="mt-1 list-inside list-disc">
                            @foreach ($preview['reasons'] as $reason)
                                <li>{{ $reason }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif

            <div class="flex justify-end">
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="save" :disabled="! $enabled">Save Invoice</x-ui.button>
            </div>
        @endif
    </form>
</div>
