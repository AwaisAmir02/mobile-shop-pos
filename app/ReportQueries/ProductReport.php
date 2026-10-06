<?php

namespace App\ReportQueries;

use App\Models\MainCategory;
use App\Models\Product;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class ProductReport implements ScreenReport
{
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

    /** Current stock valued at selling price — what the shop's on-hand inventory would bring in if sold at list price. */
    public function totalStockValue(): float
    {
        return (float) $this->query()->get()->sum(fn (Product $product) => $product->stock_quantity * $product->price);
    }

    /** Current stock valued at cost price — what the shop actually has tied up in on-hand inventory. */
    public function totalCostValue(): float
    {
        return (float) $this->query()->get()->sum(fn (Product $product) => $product->stock_quantity * (float) ($product->cost_price ?? 0));
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
