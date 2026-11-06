<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Enums\SaleStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Ledger\PostsOnChangeDate;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockIn;
use App\Models\StockWriteOff;
use App\Models\StockWriteOffItem;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock no batch covers: typed on the product form, quick-create or an import,
 * and sold through ConsumeStockFifo's fallback. Without this those sales would
 * credit Inventory that nothing debited. Its total value ever brought in is what
 * it's worth now at the product's cost, plus what's already left at the cost it
 * left with. Both sides move together, so only a real correction posts.
 */
class ProductStockBuilder implements EntryBuilder, PostsOnChangeDate
{
    public function lines(Model $document): array
    {
        $inBatches = StockIn::withoutGlobalScopes()
            ->where('product_id', $document->id)
            ->where('status', '!=', RecordStatus::Cancelled->value)
            ->pluck('quantity_remaining');

        $uncovered = Quantity::subtract($document->stock_quantity ?? 0, Quantity::sum($inBatches));
        $sign = Quantity::compare($uncovered, 0) < 0 ? -1 : 1;
        $worthNow = $sign * Quantity::money($document->cost_price ?? 0, abs($uncovered));

        $sold = SaleItem::withoutGlobalScopes()
            ->where('product_id', $document->id)
            ->whereNull('stock_in_id')
            ->whereIn('sale_id', Sale::withoutGlobalScopes()->where('shop_id', $document->shop_id)->where('status', '!=', SaleStatus::Cancelled->value)->select('id'))
            ->get()
            ->sum(fn (SaleItem $item) => $item->cost_price !== null ? Quantity::money($item->cost_price, $item->quantity) : 0.0);

        $writtenOff = StockWriteOffItem::withoutGlobalScopes()
            ->where('product_id', $document->id)
            ->whereNull('stock_in_id')
            ->whereIn('stock_write_off_id', StockWriteOff::withoutGlobalScopes()->where('shop_id', $document->shop_id)->where('status', '!=', RecordStatus::Cancelled->value)->select('id'))
            ->get()
            ->sum(fn (StockWriteOffItem $item) => $item->cost_price !== null ? Quantity::money($item->cost_price, $item->quantity) : 0.0);

        $value = round($worthNow + $sold + $writtenOff, 2);

        if ($value == 0.0) {
            return [];
        }

        return [
            Line::debit(Chart::INVENTORY, $value, $document->created_at),
            Line::credit(Chart::ADJUSTMENTS, $value, $document->created_at),
        ];
    }

    public function description(Model $document): string
    {
        return 'Stock correction: '.$document->name;
    }
}
