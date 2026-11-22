<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Enums\SalePaymentMethod;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Models\Sale;
use App\Models\SaleReturnItem;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A return, on the day it happened. Its value comes off sales (Sales Returns);
 * the part that cancelled what the customer owed comes off Receivable, and
 * money handed back leaves the cash or account it came from. Whatever went
 * back on the shelf returns to Inventory at the cost it was sold at, out of
 * cost of goods sold. A damaged unit then leaves through its own write-off.
 * A cancelled return wants no lines, so everything reverses.
 */
class SaleReturnBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $date = $document->return_date;
        $branch = $document->branch_id;
        $customer = Sale::withoutGlobalScopes()->whereKey($document->sale_id)->value('customer_id');

        $lines = [
            Line::debit(Chart::SALES_RETURNS, $document->value_total, $date, $branch),
            Line::credit(Chart::RECEIVABLE, $document->due_reduction, $date, $branch, 'customer', $customer),
            Line::credit(self::paidFrom($document), $document->refund_cash, $date, $branch),
            Line::credit(Chart::CUSTOMER_CREDIT, $document->refund_credit, $date, $branch, 'customer', $document->customer_id),
            // Paid toward the exchange's new sale, whose Exchange payment clears it.
            Line::credit(Chart::EXCHANGE_CLEARING, $document->refund_exchange, $date, $branch),
        ];

        $cost = round(SaleReturnItem::withoutGlobalScopes()
            ->where('sale_return_id', $document->id)
            ->where('restocked', true)
            ->whereNotNull('cost_price')
            ->get()
            ->sum(fn (SaleReturnItem $item) => Quantity::money($item->cost_price, $item->quantity)), 2);

        $lines[] = Line::debit(Chart::INVENTORY, $cost, $date, $branch);
        $lines[] = Line::credit(Chart::COGS, $cost, $date, $branch);

        return $lines;
    }

    public function description(Model $document): string
    {
        $sale = 'INV-'.str_pad((string) $document->sale_id, 6, '0', STR_PAD_LEFT);

        return $document->status === RecordStatus::Cancelled
            ? "Return {$document->number()} on {$sale} cancelled"
            : "Return {$document->number()} on {$sale}";
    }

    /** Money with no recorded source goes to Not Classified, as for a sale payment. */
    protected static function paidFrom(Model $document): string
    {
        return match (true) {
            $document->shop_account_id !== null => Chart::bankCode($document->shop_account_id),
            $document->refund_method === SalePaymentMethod::Cash => Chart::CASH,
            $document->refund_method === SalePaymentMethod::Account => Chart::BANK,
            default => Chart::NOT_CLASSIFIED,
        };
    }
}
