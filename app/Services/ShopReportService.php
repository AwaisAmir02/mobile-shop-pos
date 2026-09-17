<?php

namespace App\Services;

use App\Models\BalanceLoad;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\MainCategory;
use App\Models\NadraVerification;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shop;
use App\Models\SimSale;
use App\Models\StockIn;
use App\Models\WalletLoad;
use Carbon\Carbon;

class ShopReportService
{
    public function summary(Shop $shop, Carbon $start, Carbon $end): array
    {
        MainCategory::ensureDefaultsExist($shop->id);

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

        $categories = MainCategory::where('shop_id', $shop->id)->orderBy('name')->get()
            ->map(fn (MainCategory $category) => [
                'slug' => $category->slug,
                'label' => $category->name,
                'revenue' => (float) ($categoryRows->get($category->slug)->revenue ?? 0),
                'units' => (int) ($categoryRows->get($category->slug)->units ?? 0),
            ]);

        // SIM stopped being a product type (and predates Main Categories
        // entirely) but historical sales that included one must stay
        // visible here rather than silently dropping out of the breakdown.
        if ($categoryRows->has('sim')) {
            $categories->push([
                'slug' => 'sim',
                'label' => 'SIM / eSIM (Legacy)',
                'revenue' => (float) $categoryRows->get('sim')->revenue,
                'units' => (int) $categoryRows->get('sim')->units,
            ]);
        }

        $balanceLoadsQuery = fn () => BalanceLoad::where('shop_id', $shop->id)->whereBetween('created_at', [$start, $end]);

        $totalBalanceLoaded = (float) $balanceLoadsQuery()->sum('amount');
        $totalBalanceLoadFees = (float) $balanceLoadsQuery()->sum('fee') - (float) $balanceLoadsQuery()->sum('discount');

        $walletCashInQuery = fn () => WalletLoad::where('shop_id', $shop->id)->where('direction', 'cash_in')->whereBetween('created_at', [$start, $end]);
        $walletCashOutQuery = fn () => WalletLoad::where('shop_id', $shop->id)->where('direction', 'cash_out')->whereBetween('created_at', [$start, $end]);

        $totalWalletCashInAmount = (float) $walletCashInQuery()->sum('amount');
        $totalWalletCashInFees = (float) $walletCashInQuery()->sum('fee') - (float) $walletCashInQuery()->sum('discount');
        $totalWalletCashOutAmount = (float) $walletCashOutQuery()->sum('amount');
        $totalWalletCashOutFees = (float) $walletCashOutQuery()->sum('fee') - (float) $walletCashOutQuery()->sum('discount');

        // Fees are commission revenue regardless of direction, so — unlike
        // the amount figures above, which represent opposite-direction cash
        // flows and must stay separate — this combined total is safe to
        // sum for the Party Ledger's overall commission figure.
        $totalWalletLoadFees = $totalWalletCashInFees + $totalWalletCashOutFees;

        $totalExpenses = (float) Expense::where('shop_id', $shop->id)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->sum('amount');

        $totalStockInUnits = (int) StockIn::where('shop_id', $shop->id)
            ->whereBetween('stock_date', [$start->toDateString(), $end->toDateString()])
            ->sum('quantity');

        $simSalesQuery = fn () => SimSale::where('shop_id', $shop->id)->whereBetween('created_at', [$start, $end]);

        $totalSimSalesSold = (int) $simSalesQuery()->count();
        $totalSimSaleRevenue = (float) $simSalesQuery()->sum('total');

        $billPaymentsQuery = fn () => BillPayment::where('shop_id', $shop->id)->whereBetween('created_at', [$start, $end]);

        $totalBillsCollected = (float) $billPaymentsQuery()->sum('amount');
        $totalBillsFeeRevenue = (float) $billPaymentsQuery()->sum('fee') - (float) $billPaymentsQuery()->sum('discount');

        $totalRepairsRevenue = (float) Repair::where('shop_id', $shop->id)
            ->whereBetween('created_at', [$start, $end])
            ->sum('total');

        $nadraVerificationsQuery = fn () => NadraVerification::where('shop_id', $shop->id)->whereBetween('created_at', [$start, $end]);

        $totalNadraVerifications = (int) $nadraVerificationsQuery()->count();
        $totalNadraRevenue = (float) $nadraVerificationsQuery()->sum('total');

        return [
            'totalRevenue' => $totalRevenue,
            'totalDiscount' => $invoiceDiscount + $itemDiscount,
            'categories' => $categories,
            'totalItemsSold' => (int) $categories->sum('units'),
            'totalBalanceLoaded' => $totalBalanceLoaded,
            'totalBalanceLoadFees' => $totalBalanceLoadFees,
            'totalWalletCashInAmount' => $totalWalletCashInAmount,
            'totalWalletCashInFees' => $totalWalletCashInFees,
            'totalWalletCashOutAmount' => $totalWalletCashOutAmount,
            'totalWalletCashOutFees' => $totalWalletCashOutFees,
            'totalWalletLoadFees' => $totalWalletLoadFees,
            'totalExpenses' => $totalExpenses,
            'totalStockInUnits' => $totalStockInUnits,
            'totalSimSalesSold' => $totalSimSalesSold,
            'totalSimSaleRevenue' => $totalSimSaleRevenue,
            'totalBillsCollected' => $totalBillsCollected,
            'totalBillsFeeRevenue' => $totalBillsFeeRevenue,
            'totalRepairsRevenue' => $totalRepairsRevenue,
            'totalNadraVerifications' => $totalNadraVerifications,
            'totalNadraRevenue' => $totalNadraRevenue,
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
