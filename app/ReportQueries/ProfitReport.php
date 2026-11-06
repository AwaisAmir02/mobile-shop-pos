<?php

namespace App\ReportQueries;

use App\Enums\QuantityUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Services\TableExportService;
use App\Support\Quantity;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gross profit: what goods sold for, minus what those same goods cost, from
 * the batch cost frozen on each sale line. Read-only, from sale_items and sales.
 *
 * A line with no recorded cost can't say what it earned, so it's left out of
 * the profit figure altogether and counted in a notice. Treating its cost as
 * zero would quietly overstate profit. Invoice discounts are shared across a
 * sale's lines by value, so the part on those lines goes with them.
 *
 * Cancelled sales are excluded, and sales follow the branch switcher, both by
 * Sale's own global scopes. Sales Revenue here always equals the sum of
 * sales.total, the same figure the Dashboard calls Sales Revenue.
 */
class ProfitReport implements ScreenReport
{
    /** @var array<string, float|int>|null */
    protected ?array $totalsCache = null;

    public function __construct(
        protected string $from = '',
        protected string $to = '',
    ) {}

    protected function salesInRange(Builder $query): Builder
    {
        return $query
            ->when($this->from, fn ($q) => $q->whereDate('created_at', '>=', $this->from))
            ->when($this->to, fn ($q) => $q->whereDate('created_at', '<=', $this->to));
    }

    public function query(): Builder
    {
        return SaleItem::query()->whereHas('sale', fn ($query) => $this->salesInRange($query));
    }

    /**
     * @return array{revenue: float, cogs: float, grossProfit: float, costedRevenue: float, uncostedRevenue: float, invoiceDiscount: float, linesWithNoCost: int, margin: ?float}
     */
    public function totals(): array
    {
        if ($this->totalsCache !== null) {
            return $this->totalsCache;
        }

        $discounts = $this->salesInRange(Sale::query())->pluck('discount_amount', 'id');

        $perSale = $this->query()
            ->selectRaw('sale_id, SUM(line_total) as lines_total, SUM(CASE WHEN cost_price IS NULL THEN 0 ELSE line_total END) as costed_total, SUM(CASE WHEN cost_price IS NULL THEN 0 ELSE ROUND(cost_price * quantity, 2) END) as cogs, SUM(CASE WHEN cost_price IS NULL THEN 1 ELSE 0 END) as no_cost_lines')
            ->groupBy('sale_id')
            ->toBase()
            ->get();

        $costedRevenue = 0.0;
        $uncostedRevenue = 0.0;
        $cogs = 0.0;
        $lines = 0;

        foreach ($perSale as $sale) {
            $linesTotal = (float) $sale->lines_total;
            $costed = (float) $sale->costed_total;
            $discount = (float) ($discounts[$sale->sale_id] ?? 0);
            $costedDiscount = $linesTotal > 0 ? round($discount * ($costed / $linesTotal), 2) : 0.0;

            $costedRevenue += $costed - $costedDiscount;
            $uncostedRevenue += ($linesTotal - $costed) - ($discount - $costedDiscount);
            $cogs += (float) $sale->cogs;
            $lines += (int) $sale->no_cost_lines;
        }

        $costedRevenue = round($costedRevenue, 2);
        $cogs = round($cogs, 2);
        $grossProfit = round($costedRevenue - $cogs, 2);

        return $this->totalsCache = [
            'revenue' => round($costedRevenue + $uncostedRevenue, 2),
            'cogs' => $cogs,
            'grossProfit' => $grossProfit,
            'costedRevenue' => $costedRevenue,
            'uncostedRevenue' => round($uncostedRevenue, 2),
            'invoiceDiscount' => round((float) $discounts->sum(), 2),
            'linesWithNoCost' => $lines,
            'margin' => $costedRevenue > 0 ? round($grossProfit / $costedRevenue * 100, 1) : null,
        ];
    }

    /** The notice's sentence, or null when every line has a cost. */
    public function noCostNotice(): ?string
    {
        $count = $this->totals()['linesWithNoCost'];

        if ($count === 0) {
            return null;
        }

        return number_format($count).' sale '.($count === 1 ? 'line has' : 'lines have').' no recorded cost — profit may be understated.';
    }

    public function filtersSummary(): string
    {
        if (! $this->from && ! $this->to) {
            return 'All records';
        }

        $from = $this->from ? Carbon::parse($this->from)->format('d M Y') : 'earliest';
        $to = $this->to ? Carbon::parse($this->to)->format('d M Y') : 'latest';

        return "Date range: {$from} – {$to}";
    }

    public function summaryCards(): array
    {
        $totals = $this->totals();

        return [
            ['label' => 'Sales Revenue', 'value' => 'Rs '.number_format($totals['revenue'], 2), 'sub' => 'After all discounts'],
            ['label' => 'Cost of Goods Sold', 'value' => 'Rs '.number_format($totals['cogs'], 2), 'sub' => 'Batch cost of what was sold'],
            ['label' => 'Gross Profit', 'value' => 'Rs '.number_format($totals['grossProfit'], 2), 'sub' => 'Sales − cost of goods, before expenses'],
            ['label' => 'Gross Margin', 'value' => $totals['margin'] !== null ? $totals['margin'].'%' : '—', 'sub' => 'Share of sales kept as gross profit'],
        ];
    }

    /** @return array<int, array{name: string, unit: QuantityUnit, quantity: int|float, sales: float, cogs: ?float, profit: ?float, noCostLines: int}> best first */
    public function byProduct(): array
    {
        return $this->query()
            ->selectRaw('product_id, product_name, unit, SUM(quantity) as quantity_sold, SUM(line_total) as sales, SUM(CASE WHEN cost_price IS NULL THEN 0 ELSE line_total END) as costed_sales, SUM(CASE WHEN cost_price IS NULL THEN 0 ELSE ROUND(cost_price * quantity, 2) END) as cogs, SUM(CASE WHEN cost_price IS NULL THEN 1 ELSE 0 END) as no_cost_lines')
            ->groupBy('product_id', 'product_name', 'unit')
            ->toBase()
            ->get()
            ->map(function ($row) {
                $allUncosted = (float) $row->costed_sales === 0.0 && (int) $row->no_cost_lines > 0;

                return [
                    'name' => $row->product_name,
                    'unit' => QuantityUnit::fromValue($row->unit),
                    'quantity' => Quantity::normalize($row->quantity_sold),
                    'sales' => (float) $row->sales,
                    'cogs' => $allUncosted ? null : (float) $row->cogs,
                    'profit' => $allUncosted ? null : round((float) $row->costed_sales - (float) $row->cogs, 2),
                    'noCostLines' => (int) $row->no_cost_lines,
                ];
            })
            ->sortByDesc(fn (array $row) => $row['profit'] ?? -INF)
            ->values()
            ->all();
    }

    public function tableHeaders(): array
    {
        return ['Product', 'Quantity Sold', 'Unit', 'Sales (before invoice discounts)', 'Cost of Goods', 'Gross Profit', 'Lines With No Cost'];
    }

    public function tableRows(bool $forExcel): array
    {
        return array_map(function (array $row) use ($forExcel) {
            $name = TableExportService::sanitizeCell($row['name']);
            $unit = $row['unit']->symbol();

            return $forExcel
                ? [$name, $row['quantity'], $unit, $row['sales'], $row['cogs'], $row['profit'], $row['noCostLines']]
                : [$name, Quantity::plain($row['quantity']), $unit, 'Rs '.number_format($row['sales'], 2), $row['cogs'] !== null ? 'Rs '.number_format($row['cogs'], 2) : '—', $row['profit'] !== null ? 'Rs '.number_format($row['profit'], 2) : '—', (string) $row['noCostLines']];
        }, $this->byProduct());
    }

    public function columnTypes(): array
    {
        return ['string', 'quantity', 'string', 'currency', 'currency', 'currency', 'integer'];
    }
}
