<?php

namespace App\ReportQueries;

use App\Models\MainCategory;
use App\Models\Product;
use App\Services\TableExportService;
use Illuminate\Database\Eloquent\Builder;

class ProductReport implements ScreenReport
{
    public function __construct(
        protected string $search = '',
        protected string $typeFilter = '',
    ) {}

    public function query(): Builder
    {
        return Product::query()
            ->when($this->search, fn ($query) => $query->where('name', 'like', "%{$this->search}%"))
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
        return ['Product', 'Category', 'Details', 'Price', 'Cost Price', 'Stock'];
    }

    public function tableRows(bool $forExcel): array
    {
        return $this->query()->latest()->get()->map(function (Product $product) use ($forExcel) {
            $name = TableExportService::sanitizeCell($product->name);
            $category = TableExportService::sanitizeCell($product->typeLabel());
            $details = TableExportService::sanitizeCell($product->summaryLine());

            return $forExcel
                ? [$name, $category, $details, (float) $product->price, $product->cost_price !== null ? (float) $product->cost_price : null, (int) $product->stock_quantity]
                : [$name, $category, $details ?: '—', 'Rs '.number_format($product->price, 2), $product->cost_price !== null ? 'Rs '.number_format($product->cost_price, 2) : '—', (string) $product->stock_quantity];
        })->all();
    }

    public function columnTypes(): array
    {
        return ['string', 'string', 'string', 'currency', 'currency', 'integer'];
    }
}
