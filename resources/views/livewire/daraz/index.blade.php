<?php

use App\Actions\DarazImports;
use App\Livewire\Concerns\PicksPaymentMethod;
use App\Livewire\Concerns\Toasts;
use App\Models\MarketplaceImport;
use App\Support\BatchExpiry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Daraz by file (O6, App\Actions\DarazImports): the Seller Center order export
 * brings orders in and moves their parcels on; the payout statement becomes one
 * courier payout. Routed behind shop.tool:marketplace.
 */
new #[Layout('layouts.app')] #[Title('Daraz')] class extends Component
{
    use PicksPaymentMethod, Toasts, WithFileUploads;

    public $ordersFile = null;

    public $statementFile = null;

    /** The statement read but not recorded yet. */
    public ?array $preview = null;

    public string $previewName = '';

    public string $settlementDate = '';

    public string $reference = '';

    /** @var array<string, string> unmatched Seller SKU => product id chosen for it */
    public array $matchTo = [];

    public function mount(): void
    {
        $this->settlementDate = BatchExpiry::today();
    }

    public function importOrders(): void
    {
        $this->validate(['ordersFile' => ['required', 'file', 'max:10240']], [], ['ordersFile' => 'orders file']);

        $import = DarazImports::importOrders(Auth::user()->shop, $this->ordersFile->getRealPath(), $this->ordersFile->getClientOriginalName(), $this->ordersFile->getClientOriginalExtension(), Auth::id());
        $summary = $import->summary;

        $this->ordersFile = null;
        $this->toastSuccess($summary['new'].' new '.\Illuminate\Support\Str::plural('order', $summary['new']).', '.$summary['accepted'].' sold'
            .(count($summary['waiting']) > 0 ? ', '.count($summary['waiting']).' waiting for a product match' : '').'.');
    }

    public function readStatement(): void
    {
        $this->validate(['statementFile' => ['required', 'file', 'max:10240']], [], ['statementFile' => 'statement file']);

        $this->preview = DarazImports::previewStatement(Auth::user()->shop, $this->statementFile->getRealPath(), $this->statementFile->getClientOriginalExtension());
        $this->previewName = $this->statementFile->getClientOriginalName();
        $this->statementFile = null;
    }

    public function recordStatement(): void
    {
        abort_if($this->preview === null, 422);
        $this->validate(['settlementDate' => ['required', 'date', 'before_or_equal:today'], 'reference' => ['nullable', 'string', 'max:60']]);
        [$method, $accountId] = $this->chosenPaymentMethod();

        $import = DarazImports::recordStatement(Auth::user()->shop, $this->preview, [
            'settlement_date' => $this->settlementDate, 'reference' => $this->reference, 'payment_method' => $method, 'shop_account_id' => $accountId,
        ], $this->previewName, Auth::id());

        $this->preview = null;
        $this->toastSuccess('Daraz payout recorded.');
        $this->redirectRoute('courier-settlements.show', $import->courier_settlement_id, navigate: true);
    }

    public function matchSku(string $sku): void
    {
        $product = \App\Models\Product::whereNull('archived_at')->findOrFail((int) ($this->matchTo[$sku] ?? 0));
        $sold = DarazImports::matchSku(DarazImports::connection(Auth::user()->shop), $sku, $product, Auth::id());
        unset($this->matchTo[$sku]);

        $this->toastSuccess($sku.' matched to '.$product->name.($sold > 0 ? '. '.$sold.' waiting '.\Illuminate\Support\Str::plural('order', $sold).' sold.' : '.'));
    }

    public function discardStatement(): void
    {
        $this->preview = null;
    }

    public function with(): array
    {
        return [
            'imports' => MarketplaceImport::query()->latest('id')->limit(20)->get(),
            'paymentAccounts' => $this->paymentAccounts(),
            'unmatched' => DarazImports::unmatched(DarazImports::connection(Auth::user()->shop)),
            'products' => \App\Models\Product::whereNull('archived_at')->orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header subtitle="Bring in Daraz orders and payouts from Seller Center files">Daraz</x-ui.page-header>
    </x-slot>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <x-ui.card title="Orders" description="In Seller Center: Orders › Manage Orders, export. Upload the file as it comes. Orders whose Seller SKU matches a product's barcode here are sold at once; the rest wait below until you match them.">
            <form wire:submit="importOrders" class="space-y-3" data-daraz-orders>
                <x-ui.field label="Orders file" name="ordersFile" for="ordersFile" help=".xlsx, .xls or .csv">
                    <input type="file" wire:model="ordersFile" id="ordersFile" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-ink-muted">
                </x-ui.field>
                <x-ui.button type="submit" wire:loading.attr="disabled">Import orders</x-ui.button>
            </form>
        </x-ui.card>

        <x-ui.card title="Payout statement" description="In Seller Center: Finance › Account Statement or Transaction Overview, export. Daraz pays for delivered orders less its commission, payment fee, shipping fees and tax.">
            <form wire:submit="readStatement" class="space-y-3" data-daraz-statement>
                <x-ui.field label="Statement file" name="statementFile" for="statementFile" help=".xlsx, .xls or .csv">
                    <input type="file" wire:model="statementFile" id="statementFile" accept=".xlsx,.xls,.csv" class="block w-full text-sm text-ink-muted">
                </x-ui.field>
                <x-ui.button type="submit" wire:loading.attr="disabled">Read statement</x-ui.button>
            </form>
        </x-ui.card>
    </div>

    @if ($unmatched !== [])
        <x-ui.card class="mt-6" title="Seller SKUs to match" description="These Daraz orders are waiting: their Seller SKU isn't a barcode here. Pick the product once; later files match it by themselves." data-daraz-unmatched>
            <div class="space-y-2 text-sm">
                @foreach ($unmatched as $item)
                    <div class="flex flex-wrap items-end gap-2" wire:key="sku-{{ $item['sku'] }}">
                        <div class="min-w-0 flex-1">
                            <span class="block truncate text-ink">{{ $item['name'] }}</span>
                            <span class="text-xs text-ink-faint">{{ $item['sku'] }} · {{ $item['orders'] }} {{ \Illuminate\Support\Str::plural('order', $item['orders']) }}</span>
                        </div>
                        <x-ui.select wire:model="matchTo.{{ $item['sku'] }}" aria-label="Product for {{ $item['sku'] }}">
                            <option value="">Choose…</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->name }}</option>
                            @endforeach
                        </x-ui.select>
                        <x-ui.button size="sm" variant="secondary" wire:click="matchSku(@js($item['sku']))">Match</x-ui.button>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    @endif

    @if ($preview !== null)
        <x-ui.card class="mt-6" :title="'Statement: '.$previewName" description="Check it against Daraz's own total before recording." data-statement-preview>
            <div class="space-y-4 text-sm">
                <x-ui.table stack :headers="['Daraz order', 'Credited for items', 'Charges', '']">
                    @foreach ($preview['lines'] as $line)
                        <x-ui.table-row wire:key="line-{{ $line['order'] }}">
                            <x-ui.table-cell primary>{{ $line['order'] }}</x-ui.table-cell>
                            <x-ui.table-cell label="Credited" class="tabular-nums">Rs {{ number_format($line['collected'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell label="Charges" class="tabular-nums">Rs {{ number_format($line['charges'], 2) }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $line['returned'] ? 'Returned' : '' }}</x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
                <dl class="space-y-1">
                    <div class="flex justify-between"><dt class="text-ink-faint">Tax withheld</dt><dd class="tabular-nums">Rs {{ number_format($preview['tax'], 2) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-ink-faint">Other deductions (no order)</dt><dd class="tabular-nums">Rs {{ number_format($preview['other'], 2) }}</dd></div>
                    <div class="flex justify-between font-semibold"><dt>Paid to you</dt><dd class="tabular-nums" data-preview-net>Rs {{ number_format($preview['net'], 2) }}</dd></div>
                    <div class="flex justify-between text-xs"><dt class="text-ink-faint">The file's own total</dt><dd class="tabular-nums">Rs {{ number_format($preview['file_total'], 2) }}</dd></div>
                </dl>
                @if ($preview['skipped'] !== [])
                    <x-ui.alert variant="warning">
                        {{ count($preview['skipped']) }} {{ \Illuminate\Support\Str::plural('order', count($preview['skipped'])) }} left out:
                        @foreach (array_slice($preview['skipped'], 0, 5) as $skip) <span class="block text-xs">{{ $skip['order'] }}: {{ $skip['reason'] }}</span> @endforeach
                    </x-ui.alert>
                @endif
                @if ($preview['credits_unplaced'] > 0)
                    <x-ui.alert variant="warning">Rs {{ number_format($preview['credits_unplaced'], 2) }} of credits had no charges to come off. Record it as money in by hand if it reached you.</x-ui.alert>
                @endif
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-ui.field label="Paid on" name="settlementDate" for="settlementDate">
                        <x-ui.input wire:model="settlementDate" id="settlementDate" type="date" />
                    </x-ui.field>
                    <x-ui.field label="Statement number" name="reference" for="reference">
                        <x-ui.input wire:model="reference" id="reference" maxlength="60" />
                    </x-ui.field>
                    <div wire:key="paid-into-{{ $paymentMethod }}">
                        <x-ui.field label="Paid into" name="paymentMethod" for="paymentMethod">
                            <x-ui.select wire:model.live="paymentMethod" id="paymentMethod">
                                <option value="">Choose…</option>
                                <option value="cash">Cash</option>
                                @foreach ($paymentAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }} · {{ $account->provider_type }}</option>
                                @endforeach
                            </x-ui.select>
                        </x-ui.field>
                    </div>
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="secondary" wire:click="discardStatement">Discard</x-ui.button>
                    <x-ui.button wire:click="recordStatement" :disabled="$preview['lines'] === []" data-record-statement>Record payout</x-ui.button>
                </div>
            </div>
        </x-ui.card>
    @endif

    <h2 class="mb-3 mt-8 text-base font-bold text-ink">Files brought in</h2>
    @if ($imports->isEmpty())
        <x-ui.empty-state title="No Daraz files yet" description="Import an orders file to start." />
    @else
        <x-ui.table stack :headers="['File', 'Kind', 'What it did', 'When']">
            @foreach ($imports as $import)
                <x-ui.table-row wire:key="import-{{ $import->id }}">
                    <x-ui.table-cell primary>{{ $import->file_name }}</x-ui.table-cell>
                    <x-ui.table-cell label="Kind">{{ $import->kind->label() }}</x-ui.table-cell>
                    <x-ui.table-cell label="What it did">
                        @if ($import->kind === \App\Enums\MarketplaceImportKind::Orders)
                            {{ $import->summary['new'] ?? 0 }} new · {{ $import->summary['accepted'] ?? 0 }} sold · {{ $import->summary['moved'] ?? 0 }} moved on · {{ $import->summary['cancelled'] ?? 0 }} cancelled
                            @if (! empty($import->summary['waiting']))
                                · {{ count($import->summary['waiting']) }} waiting for a match
                            @endif
                        @elseif ($import->settlement)
                            <a href="{{ route('courier-settlements.show', $import->settlement) }}" wire:navigate class="text-link hover:underline">{{ $import->settlement->number() }}</a> · Rs {{ number_format($import->settlement->net_amount, 2) }}
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="When">{{ $import->created_at->format('d M Y H:i') }}</x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @endif

    <livewire:shop-accounts.quick-create />
</div>
