<?php

namespace App\ReportQueries;

use App\Models\MainCategory;
use App\Models\Product;
use App\Models\StockIn;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductReport implements ScreenReport
{
    /** @var array{selling: float, cost: float}|null */
    protected ?array $valuationCache = null;

    public function __construct(
        protected string $search = '',
        protected string $typeFilter = '',
    ) {}

    protected function barcodesEnabled(): bool
    {
        return (bool) Auth::user()?->canUseBarcodes();
    }

    public function query(): Builder
    {
        return Product::query()
            ->when($this->search, fn ($query) => $query->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->when($this->barcodesEnabled(), fn ($inner) => $inner->orWhere('barcode', 'like', "%{$this->search}%"))))
            ->when($this->typeFilter, fn ($query) => $query->where('type', $this->typeFilter));
    }

    public function totalProducts(): int
    {
        return $this->query()->count();
    }

    /**
     * Each product's stock is valued batch by batch (a product with two
     * batches at two different prices is not one blended figure), with any
     * quantity not covered by a batch — legacy stock that predates batch
     * tracking — valued at the product's own price/cost as a fallback.
     *
     * @return array{selling: float, cost: float}
     */
    protected function batchAwareValuation(): array
    {
        if ($this->valuationCache !== null) {
            return $this->valuationCache;
        }

        $products = $this->query()->get();

        $batchesByProduct = StockIn::query()
            ->whereIn('product_id', $products->pluck('id'))
            ->where('quantity_remaining', '>', 0)
            ->get()
            ->groupBy('product_id');

        $sellingTotal = 0.0;
        $costTotal = 0.0;

        foreach ($products as $product) {
            $batches = $batchesByProduct->get($product->id, collect());
            $remainingInBatches = (int) $batches->sum('quantity_remaining');

            $sellingTotal += (float) $batches->sum(fn (StockIn $batch) => $batch->quantity_remaining * (float) ($batch->selling_price ?? $product->price));
            $costTotal += (float) $batches->sum(fn (StockIn $batch) => $batch->quantity_remaining * (float) ($batch->cost_price ?? $product->cost_price ?? 0));

            $uncovered = max(0, $product->stock_quantity - $remainingInBatches);
            $sellingTotal += $uncovered * (float) $product->price;
            $costTotal += $uncovered * (float) ($product->cost_price ?? 0);
        }

        return $this->valuationCache = ['selling' => $sellingTotal, 'cost' => $costTotal];
    }

    /** Current stock valued at selling price — what the shop's on-hand inventory would bring in if sold at list price. */
    public function totalStockValue(): float
    {
        return $this->batchAwareValuation()['selling'];
    }

    /** Current stock valued at cost price — what the shop actually has tied up in on-hand inventory. */
    public function totalCostValue(): float
    {
        return $this->batchAwareValuation()['cost'];
    }

    public function filtersSummary(): string
    {
        $parts = [];

        if ($this->search) {
            $parts[] = 'Search: "'.$this->search.'"';
        }

        if ($this->typeFilter) {
            $label = MainCategory::where('slug', $this->typeFilter)->value('name')
                ?? ($this->typeFilter === 'sim' ? 'SIM / eSIM (Legacy)' : $this->typeFilter);
            $parts[] = 'Category: '.$label;
        }

        return $parts ? implode('; ', $parts) : 'All records';
    }

    public function summaryCards(): array
    {
        return [
            ['label' => 'Total Products', 'value' => number_format($this->totalProducts())],
            ['label' => 'Total Stock Value', 'value' => 'Rs '.number_format($this->totalStockValue(), 2), 'sub' => 'At selling price'],
            ['label' => 'Total Cost Value', 'value' => 'Rs '.number_format($this->totalCostValue(), 2), 'sub' => 'At cost price'],
        ];
    }

    public function tableHeaders(): array
    {
        return collect(['Product', 'Category', 'Details', ...($this->barcodesEnabled() ? ['Barcode'] : []), 'Price', 'Cost Price', 'Stock'])->all();
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest()->get()->map(function (Product $product) use ($forExcel) {
            $name = TableExportService::sanitizeCell($product->name);
            $category = TableExportService::sanitizeCell($product->typeLabel());
            $details = TableExportService::sanitizeCell($product->summaryLine());
            $barcode = TableExportService::sanitizeCell((string) ($product->barcode ?? ''));

            $barcodeCell = $this->barcodesEnabled() ? [$forExcel ? $barcode : ($barcode ?: '—')] : [];

            return $forExcel
                ? [$name, $category, $details, ...$barcodeCell, (float) $product->price, $product->cost_price !== null ? (float) $product->cost_price : null, (int) $product->stock_quantity]
                : [$name, $category, $details ?: '—', ...$barcodeCell, 'Rs '.number_format($product->price, 2), $product->cost_price !== null ? 'Rs '.number_format($product->cost_price, 2) : '—', (string) $product->stock_quantity];
        })->all();
    }

    public function columnTypes(): array
    {
        return collect(['string', 'string', 'string', ...($this->barcodesEnabled() ? ['string'] : []), 'currency', 'currency', 'integer'])->all();
    }
}
