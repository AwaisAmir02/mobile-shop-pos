<?php

namespace App\Services;

use App\Enums\ProductType;
use App\Models\BalanceLoad;
use App\Models\Expense;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
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

        $totalExpenses = (float) Expense::where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        return [
            'totalRevenue' => $totalRevenue,
            'totalDiscount' => $invoiceDiscount + $itemDiscount,
            'categories' => $categories,
            'totalBalanceLoaded' => $totalBalanceLoaded,
            'totalExpenses' => $totalExpenses,
            'netSummary' => $totalRevenue - $totalExpenses,
        ];
    }
}
