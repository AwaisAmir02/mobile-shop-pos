<?php

namespace App\Ledger\Builders;

use App\Enums\SaleLineKind;
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
        // A delivery charge (O2) is revenue on its own line; without one the entry is as it always was.
        $delivery = round((float) $document->delivery_charge, 2);
        // Services and memberships on the bill (Services group) are service sales, before discounts like goods.
        $services = round($items->filter(fn (SaleItem $item) => in_array($item->line_kind, [SaleLineKind::Service, SaleLineKind::Membership], true))->sum(fn (SaleItem $item) => (float) $item->line_total + (float) $item->discount_amount), 2);
        $lines = [
            Line::debit(Chart::RECEIVABLE, $document->total, $date, $branch, 'customer', $customer),
            Line::debit(Chart::DISCOUNTS, $discounts, $date, $branch),
            Line::credit(Chart::SALES, round((float) $document->total + $discounts - $delivery - $services, 2), $date, $branch),
        ];

        if ($delivery > 0) {
            $lines[] = Line::credit(Chart::DELIVERY_INCOME, $delivery, $date, $branch);
        }

        if ($services > 0) {
            $lines[] = Line::credit(Chart::SERVICE_SALES, $services, $date, $branch);
        }

        $cost = round($items->sum(fn (SaleItem $item) => $item->cost_price !== null ? Quantity::money($item->cost_price, $item->quantity) : 0.0), 2);
        $lines[] = Line::debit(Chart::COGS, $cost, $date, $branch);
        $lines[] = Line::credit(Chart::INVENTORY, $cost, $date, $branch);

        foreach (SalePayment::withoutGlobalScopes()->where('sale_id', $document->id)->get() as $payment) {
            // Store credit is owed to one customer, so it keeps their name on the line.
            $lines[] = match ($payment->payment_method) {
                SalePaymentMethod::StoreCredit => Line::debit(Chart::CUSTOMER_CREDIT, $payment->amount, $payment->payment_date, $branch, 'customer', $customer),
                // A deposit was held for this customer; a gift voucher for whoever had the code.
                SalePaymentMethod::Deposit => Line::debit(Chart::CUSTOMER_DEPOSITS, $payment->amount, $payment->payment_date, $branch, 'customer', $customer),
                SalePaymentMethod::GiftVoucher => Line::debit(Chart::GIFT_VOUCHERS, $payment->amount, $payment->payment_date, $branch),
                default => Line::debit(self::paidInto($payment), $payment->amount, $payment->payment_date, $branch),
            };
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
            $payment->payment_method === SalePaymentMethod::TradeIn => Chart::TRADE_IN,
            $payment->payment_method === SalePaymentMethod::Exchange => Chart::EXCHANGE_CLEARING,
            $payment->payment_method === SalePaymentMethod::Contra => Chart::CONTRA_CLEARING,
            // The money reaches an account with the payout, net of the courier's deductions (CourierSettlementBuilder).
            $payment->payment_method === SalePaymentMethod::Courier => Chart::COURIER_CLEARING,
            default => Chart::NOT_CLASSIFIED,
        };
    }
}
