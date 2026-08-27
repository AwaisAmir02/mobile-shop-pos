<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\BalanceLoad;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\WalletLoad;
use Carbon\Carbon;

class ShopReportService
{
    public function summary(Shop $shop, Carbon $start, Carbon $end): array
    {
        $salesQuery = fn () => Sale::where('shop_id', $shop->id)->whereBetween('created_at', [$start, $end]);

        $saleItemsQuery = fn () => SaleItem::where('shop_id', $shop->id)
            ->whereHas('sale', fn ($query) => $query->whereBetween('created_at', [$start, $end]));

        $totalRevenue = (float) $salesQuery()->sum('total');
        $invoiceDiscount = (float) $salesQuery()->sum('discount_amount');
        $itemDiscount = (float) $saleItemsQuery()->sum('discount_amount');

        $categoryRows = $saleItemsQuery()
            ->selectRaw('product_type, SUM(line_total) as revenue, SUM(quantity) as units')
            ->groupBy('product_type')
            ->get()
            ->keyBy('product_type');

        $categories = collect(ProductType::cases())->map(fn (ProductType $type) => [
            'label' => $type->label(),
            'revenue' => (float) ($categoryRows->get($type->value)->revenue ?? 0),
            'units' => (int) ($categoryRows->get($type->value)->units ?? 0),
        ]);

        $totalBalanceLoaded = (float) BalanceLoad::where('shop_id', $shop->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalWalletLoaded = (float) WalletLoad::where('shop_id', $shop->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('amount');

        $totalExpenses = (float) Expense::where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        $totalStockInUnits = (int) StockIn::where('shop_id', $shop->id)
            ->whereBetween('stock_date', [$start->toDateString(), $end->toDateString()])
            ->sum('quantity');

        return [
            'totalRevenue' => $totalRevenue,
            'totalDiscount' => $invoiceDiscount + $itemDiscount,
            'categories' => $categories,
            'totalItemsSold' => (int) $categories->sum('units'),
            'totalBalanceLoaded' => $totalBalanceLoaded,
            'totalWalletLoaded' => $totalWalletLoaded,
            'totalExpenses' => $totalExpenses,
            'totalStockInUnits' => $totalStockInUnits,
            'netSummary' => $totalRevenue - $totalExpenses,
        ];
    }

    public function revenueTrend(Shop $shop, string $periodType): array
    {
        $buckets = match ($periodType) {
            'year' => 6,
            'month' => 12,
            default => 14,
        };

        $points = [];

        for ($i = $buckets - 1; $i >= 0; $i--) {
            [$start, $end, $label] = match ($periodType) {
                'year' => [
                    now()->subYears($i)->startOfYear(),
                    now()->subYears($i)->endOfYear(),
                    now()->subYears($i)->format('Y'),
                ],
                'month' => [
                    now()->subMonthsNoOverflow($i)->startOfMonth(),
                    now()->subMonthsNoOverflow($i)->endOfMonth(),
                    now()->subMonthsNoOverflow($i)->format('M Y'),
                ],
                default => [
                    now()->subDays($i)->startOfDay(),
                    now()->subDays($i)->endOfDay(),
                    now()->subDays($i)->format('d M'),
                ],
            };

            $revenue = (float) Sale::where('shop_id', $shop->id)
                ->whereBetween('created_at', [$start, $end])
                ->sum('total');

            $points[] = ['label' => $label, 'value' => $revenue];
        }

        return $points;
    }
}
