<?php

namespace App\ReportQueries;

use App\Enums\SaleStatus;
use App\Models\DeliveryRoute;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales by booker, by route or by brand (W8), with each one's cost and gross
 * profit: the same figures as the Gross Profit report (sale lines at the cost
 * they left stock at), before returns. Cancelled sales are left out. Can be
 * narrowed to one booker or one route, to see that person's or beat's brands.
 */
class SalesPerformance
{
    public const GROUPINGS = ['booker', 'route', 'brand'];

    public function __construct(
        public readonly int $shopId,
        public readonly string $by,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $bookerId = null,
        public readonly ?int $routeId = null,
    ) {}

    /** @return Collection<int, array{name: string, sales: int, revenue: float, cost: float, profit: float, margin: ?float, noCost: bool}> biggest first */
    public function rows(): Collection
    {
        $group = match ($this->by) {
            'route' => 'customers.route_id',
            'brand' => "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(products.details, '$.brand')), ''), '')",
            default => 'sales.booker_id',
        };

        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.shop_id', $this->shopId)
            ->where('sales.status', '!=', SaleStatus::Cancelled->value)
            ->whereDate('sales.created_at', '>=', $this->from)
            ->whereDate('sales.created_at', '<=', $this->to)
            ->when($this->bookerId !== null, fn ($q) => $q->where('sales.booker_id', $this->bookerId))
            ->when($this->routeId !== null, fn ($q) => $q->where('customers.route_id', $this->routeId))
            ->groupByRaw($group)
            ->selectRaw("{$group} as grp, COUNT(DISTINCT sales.id) as sales, SUM(sale_items.line_total) as revenue, SUM(CASE WHEN sale_items.cost_price IS NULL THEN 0 ELSE sale_items.cost_price * sale_items.quantity END) as cost, SUM(CASE WHEN sale_items.cost_price IS NULL THEN 1 ELSE 0 END) as no_cost")
            ->get();

        $names = match ($this->by) {
            'route' => DeliveryRoute::query()->pluck('name', 'id'),
            'booker' => User::query()->where('shop_id', $this->shopId)->pluck('name', 'id'),
            default => collect(),
        };

        return $rows->map(function ($row) use ($names) {
            $revenue = round((float) $row->revenue, 2);
            $cost = round((float) $row->cost, 2);

            return [
                'name' => match ($this->by) {
                    'brand' => $row->grp !== '' && $row->grp !== null ? $row->grp : 'No brand',
                    'route' => $names[$row->grp] ?? 'No route',
                    default => $names[$row->grp] ?? 'Not booked (counter)',
                },
                'sales' => (int) $row->sales,
                'revenue' => $revenue,
                'cost' => $cost,
                'profit' => round($revenue - $cost, 2),
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
                'noCost' => (int) $row->no_cost > 0,
            ];
        })->sortByDesc('revenue')->values();
    }

    /** @return array{sales: int, revenue: float, cost: float, profit: float} */
    public static function totals(Collection $rows): array
    {
        return ['sales' => $rows->sum('sales'), 'revenue' => round($rows->sum('revenue'), 2), 'cost' => round($rows->sum('cost'), 2), 'profit' => round($rows->sum('profit'), 2)];
    }
}
