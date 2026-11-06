<?php

namespace App\Ledger\Builders;

use App\Enums\SalePaymentMethod;
use App\Enums\SaleStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A goods sale, and any shortfall on it: that shortfall is goods on credit, so
 * it sits in Receivable against the sale's revenue, never as a cash loan. A
 * cancelled sale wants no lines at all, so everything reverses, payments
 * included: the cash is assumed to have gone back, and the entry says so.
 */
class SaleBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === SaleStatus::Cancelled) {
            return [];
        }

        $date = $document->created_at;
        $branch = $document->branch_id;
        $customer = $document->customer_id;
        $items = SaleItem::withoutGlobalScopes()->where('sale_id', $document->id)->get();

        $discounts = round($items->sum(fn (SaleItem $item) => (float) $item->discount_amount) + (float) $document->discount_amount, 2);
        $lines = [
            Line::debit(Chart::RECEIVABLE, $document->total, $date, $branch, 'customer', $customer),
            Line::debit(Chart::DISCOUNTS, $discounts, $date, $branch),
            Line::credit(Chart::SALES, round((float) $document->total + $discounts, 2), $date, $branch),
        ];

        $cost = round($items->sum(fn (SaleItem $item) => $item->cost_price !== null ? Quantity::money($item->cost_price, $item->quantity) : 0.0), 2);
        $lines[] = Line::debit(Chart::COGS, $cost, $date, $branch);
        $lines[] = Line::credit(Chart::INVENTORY, $cost, $date, $branch);

        foreach (SalePayment::withoutGlobalScopes()->where('sale_id', $document->id)->get() as $payment) {
            $lines[] = Line::debit(self::paidInto($payment), $payment->amount, $payment->payment_date, $branch);
            $lines[] = Line::credit(Chart::RECEIVABLE, $payment->amount, $payment->payment_date, $branch, 'customer', $customer);
        }

        return $lines;
    }

    public function description(Model $document): string
    {
        $number = $document->invoiceNumber();

        if ($document->status !== SaleStatus::Cancelled) {
            return "Sale {$number}";
        }

        $paid = SalePayment::withoutGlobalScopes()->where('sale_id', $document->id)->where('amount', '!=', 0)->exists();

        return $paid ? "Sale {$number} cancelled: cash assumed returned" : "Sale {$number} cancelled";
    }

    /** Money with no recorded method goes to Not Classified rather than being guessed as cash. */
    protected static function paidInto(SalePayment $payment): string
    {
        return match (true) {
            $payment->shop_account_id !== null => Chart::bankCode($payment->shop_account_id),
            $payment->payment_method === SalePaymentMethod::Cash => Chart::CASH,
            $payment->payment_method === SalePaymentMethod::Account => Chart::BANK,
            default => Chart::NOT_CLASSIFIED,
        };
    }
}
