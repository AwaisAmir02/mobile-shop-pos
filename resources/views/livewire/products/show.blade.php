<?php

use App\Enums\SaleStatus;
use App\Models\Product;
use App\Models\SaleItem;
use App\Models\StockIn;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * One product on its own page (design overhaul D5): what it is, its variants,
 * its stock batches and its recent sales, together. Read-only; editing goes
 * back to the Products screen's form. Every query goes through the shop- and
 * branch-scoped models, and each section shows only to someone who can open
 * the screen it comes from.
 */
new #[Layout('layouts.app')] class extends Component
{
    public Product $product;

    #[Url]
    public string $tab = 'overview';

    public function mount(Product $product): void
    {
        $this->product = $product->load('group');

        if (! array_key_exists($this->tab, $this->tabs())) {
            $this->tab = 'overview';
        }
    }

    public function rendering($view): void
    {
        $view->title($this->product->name);
    }

    /** @return array<string, string> */
    protected function tabs(): array
    {
        $user = Auth::user();

        return array_filter([
            'overview' => 'Overview',
            'variants' => $this->product->product_group_id !== null ? 'Variants' : null,
            'batches' => $user->hasAccessTo('stock-ins') ? 'Batches' : null,
            'sales' => $user->hasAccessTo('sales') ? 'Sales' : null,
        ]);
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, $this->tabs())) {
            $this->tab = $tab;
        }
    }

    protected function saleItems()
    {
        return SaleItem::query()
            ->where('product_id', $this->product->id)
            ->whereHas('sale');
    }

    public function with(): array
    {
        $user = Auth::user();
        $variants = $this->product->product_group_id !== null
            ? Product::query()->where('product_group_id', $this->product->product_group_id)->orderBy('variant_label')->get()
            : collect();

        return [
            'tabs' => $this->tabs(),
            'canSeeCost' => $user->hasAccessTo('sales-view-cost-price'),
            'canSeeSales' => $user->hasAccessTo('sales'),
            'canAddStock' => $user->hasAccessTo('stock-ins'),
            'barcodeEnabled' => $user->canUseBarcodes(),
            'expiryEnabled' => (bool) $user->shop?->expiry_tracking_enabled,
            'whatsappEnabled' => (bool) $user->shop?->whatsapp_ordering_enabled,
            'soldLast30' => $user->hasAccessTo('sales')
                ? (float) $this->saleItems()->whereHas('sale', fn ($q) => $q->where('status', '!=', SaleStatus::Cancelled->value)->where('created_at', '>=', now()->subDays(30)))->sum('quantity')
                : null,
            'variants' => $variants,
            'variantPrices' => $variants->isNotEmpty() ? Product::sellingPricesFor($variants) : [],
            'batches' => $this->tab === 'batches' && $user->hasAccessTo('stock-ins')
                ? StockIn::query()->where('product_id', $this->product->id)->with('vendor')->latest('stock_date')->latest('id')->limit(50)->get()
                : collect(),
            'sales' => $this->tab === 'sales' && $user->hasAccessTo('sales')
                ? $this->saleItems()->with('sale')->latest('id')->limit(30)->get()
                : collect(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <x-ui.page-header back="{{ route('products.index') }}" back-label="Products" :subtitle="$product->group ? 'Variant of '.$product->group->name : $product->typeLabel()">
            {{ $product->name }}
            <x-slot:actions>
                @if ($canAddStock)
                    <x-ui.button href="{{ route('stock-ins.index') }}" wire:navigate variant="secondary" size="sm">Add Stock</x-ui.button>
                @endif
                @if ($product->type !== 'sim')
                    <x-ui.button href="{{ route('products.index', ['edit' => $product->id]) }}" wire:navigate size="sm">Edit</x-ui.button>
                @endif
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot>

    @if (count($tabs) > 1)
        <div class="mb-5 inline-flex flex-wrap rounded-full border border-line bg-surface p-1 shadow-soft" role="tablist" aria-label="Product sections">
            @foreach ($tabs as $key => $label)
                <button
                    type="button"
                    role="tab"
                    wire:click="setTab('{{ $key }}')"
                    aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                    data-product-tab="{{ $key }}"
                    @class([
                        'rounded-full px-4 py-1.5 text-sm font-semibold transition-colors',
                        'bg-primary text-on-primary shadow-soft' => $tab === $key,
                        'text-ink-faint hover:text-ink' => $tab !== $key,
                    ])
                >{{ $label }}</button>
            @endforeach
        </div>
    @endif

    @if ($tab === 'overview')
        <x-ui.stat-row>
            <x-ui.stat label="Selling price" :value="'Rs '.number_format($product->currentSellingPrice(), 2).$product->unit->perSuffix()" />
            <x-ui.stat label="In stock" :value="$product->stockLabel()" :sub="$product->stock_quantity > 0 ? null : 'Out of stock'" />
            @if ($canSeeCost)
                <x-ui.stat label="Cost price" :value="$product->currentCostPrice() !== null ? 'Rs '.number_format($product->currentCostPrice(), 2) : '—'" sub="From the next batch to sell" />
            @endif
            @if ($soldLast30 !== null)
                <x-ui.stat label="Sold, last 30 days" :value="$product->unit->amount($soldLast30)" />
            @endif
        </x-ui.stat-row>

        <x-ui.card title="Details">
            <dl class="grid grid-cols-1 gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                <div class="flex items-start gap-3 sm:col-span-2">
                    <x-ui.thumbnail :src="$product->imageUrl()" :label="$product->name" class="!h-16 !w-16 !rounded-2xl text-lg" />
                    <div>
                        <dt class="text-ink-faint">Category</dt>
                        <dd class="font-semibold text-ink">{{ $product->typeLabel() }}</dd>
                        @if ($line = $product->summaryLine())
                            <dd class="text-ink-muted">{{ $line }}</dd>
                        @endif
                    </div>
                </div>
                <div>
                    <dt class="text-ink-faint">Sold by</dt>
                    <dd class="font-semibold text-ink">{{ $product->unit->label() }}</dd>
                </div>
                @if ($barcodeEnabled)
                    <div>
                        <dt class="text-ink-faint">Barcode</dt>
                        <dd class="font-mono font-semibold text-ink">{{ $product->barcode ?? '—' }}</dd>
                    </div>
                @endif
                @if ($expiryEnabled)
                    <div>
                        <dt class="text-ink-faint">Shelf life</dt>
                        <dd class="font-semibold text-ink">{{ $product->shelf_life_days ? $product->shelf_life_days.' days' : '—' }}</dd>
                    </div>
                @endif
                @if ($whatsappEnabled)
                    <div>
                        <dt class="text-ink-faint">On WhatsApp</dt>
                        <dd class="font-semibold text-ink">{{ $product->whatsapp_listed ? 'Listed' : 'Not listed' }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-ink-faint">Status</dt>
                    <dd>
                        @if ($product->isArchived())
                            <x-ui.badge variant="neutral">Archived</x-ui.badge>
                        @else
                            <x-products.stock-badge :product="$product" />
                        @endif
                    </dd>
                </div>
            </dl>
        </x-ui.card>
    @elseif ($tab === 'variants')
        <x-ui.table stack :headers="['Variant', 'Price', 'Stock', '']">
            @foreach ($variants as $variant)
                <x-ui.table-row wire:key="variant-{{ $variant->id }}" @class(['bg-primary-soft/40' => $variant->id === $product->id])>
                    <x-ui.table-cell primary class="font-semibold text-ink">
                        {{ $variant->variant_label }}
                        @if ($variant->id === $product->id)
                            <span class="ui-sub">This product</span>
                        @endif
                    </x-ui.table-cell>
                    <x-ui.table-cell label="Price">Rs {{ number_format($variantPrices[$variant->id] ?? $variant->price, 2) }}{{ $variant->unit->perSuffix() }}</x-ui.table-cell>
                    <x-ui.table-cell label="Stock"><x-products.stock-badge :product="$variant" /></x-ui.table-cell>
                    <x-ui.table-cell actions align="right">
                        @if ($variant->id !== $product->id)
                            <x-ui.button href="{{ route('products.show', $variant) }}" wire:navigate size="sm" variant="ghost">Open</x-ui.button>
                        @endif
                    </x-ui.table-cell>
                </x-ui.table-row>
            @endforeach
        </x-ui.table>
    @elseif ($tab === 'batches')
        @if ($batches->isEmpty())
            <x-ui.empty-state title="No stock received yet" description="Batches appear here when this product is received through Stock In." />
        @else
            <x-ui.table stack :headers="['Received', 'Quantity', ...($expiryEnabled ? ['Expires'] : []), 'Cost / Sell', 'Payment']">
                @foreach ($batches as $batch)
                    <x-ui.table-row wire:key="batch-{{ $batch->id }}" @class(['opacity-50' => $batch->isCancelled()])>
                        <x-ui.table-cell primary class="font-semibold text-ink">
                            {{ $batch->stock_date->format('d M Y') }}
                            <span class="ui-sub">{{ $batch->vendor?->name ?? 'No vendor' }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Quantity">
                            +{{ $batch->unit->groupedAmount($batch->quantity) }}
                            @unless ($batch->isCancelled())
                                <span class="ui-sub">{{ $batch->unit->groupedAmount($batch->quantity_remaining) }} left</span>
                            @endunless
                        </x-ui.table-cell>
                        @if ($expiryEnabled)
                            <x-ui.table-cell label="Expires"><span @class(['font-semibold text-danger' => $batch->isExpired() && $batch->quantity_remaining > 0])>{{ $batch->expiry_date?->format('d M Y') ?? '—' }}</span></x-ui.table-cell>
                        @endif
                        <x-ui.table-cell label="Cost / Sell">
                            {{ $batch->cost_price !== null ? 'Rs '.number_format($batch->cost_price, 2) : '—' }}
                            <span class="ui-sub">sells {{ $batch->selling_price !== null ? 'Rs '.number_format($batch->selling_price, 2) : '—' }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Payment">
                            @if ($batch->isCancelled())
                                <x-ui.badge variant="danger">Cancelled</x-ui.badge>
                            @elseif ($batch->payment_status->value === 'paid')
                                <x-ui.badge variant="success">Paid</x-ui.badge>
                            @elseif ($batch->payment_status->value === 'partial')
                                <x-ui.badge variant="warning">Partial</x-ui.badge>
                            @else
                                <x-ui.badge variant="danger">Unpaid</x-ui.badge>
                            @endif
                        </x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <p class="mt-3 text-sm text-ink-faint">Latest 50. <a href="{{ route('stock-ins.history', ['productFilter' => $product->id]) }}" wire:navigate class="font-semibold text-link hover:underline">See all in Stock In History</a></p>
        @endif
    @elseif ($tab === 'sales')
        @if ($sales->isEmpty())
            <x-ui.empty-state title="Not sold yet" description="Sales of this product appear here." />
        @else
            <x-ui.table stack :headers="['Invoice', 'Quantity', 'Price', 'Total']">
                @foreach ($sales as $item)
                    <x-ui.table-row wire:key="sale-item-{{ $item->id }}" @class(['opacity-50' => $item->sale->isCancelled()])>
                        <x-ui.table-cell primary class="font-semibold text-ink">
                            <a href="{{ route('sales.show', $item->sale) }}" wire:navigate class="hover:text-link">{{ $item->sale->invoiceNumber() }}</a>
                            <span class="ui-sub">{{ $item->sale->created_at->format('d M Y, h:i A') }}{{ $item->sale->isCancelled() ? ' · Cancelled' : '' }}</span>
                        </x-ui.table-cell>
                        <x-ui.table-cell label="Quantity">{{ $item->unit->amount($item->quantity) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Price">Rs {{ number_format($item->unit_price, 2) }}</x-ui.table-cell>
                        <x-ui.table-cell label="Total" class="font-semibold text-ink">Rs {{ number_format($item->line_total, 2) }}</x-ui.table-cell>
                    </x-ui.table-row>
                @endforeach
            </x-ui.table>
            <p class="mt-3 text-sm text-ink-faint">Latest 30 sales of this product.</p>
        @endif
    @endif
</div>
