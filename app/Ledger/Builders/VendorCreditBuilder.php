<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Models\Cheque;
use App\Models\VendorCredit;
use App\Models\VendorCreditRefund;
use Illuminate\Database\Eloquent\Model;

/**
 * A vendor credit: what a vendor owes the shop back. It arises from goods
 * returned (out of Inventory, at their batch cost), a settled scheme claim
 * (income), or a cheque that paid more than was owed (out of the bank it was
 * drawn on). Taking it off unpaid stock posts with that Stock In (a payment of
 * method vendor_credit: Payable against this account), and a refund brings the
 * money in here. A cancelled credit wants no lines: its stock payments were
 * reversed on their own rows.
 */
class VendorCreditBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === 'cancelled') {
            return [];
        }

        $date = $document->entry_date;
        $vendor = $document->vendor_id;
        $amount = (float) $document->amount;

        $from = match ($document->source) {
            VendorCredit::DEBIT_NOTE => Chart::INVENTORY,
            VendorCredit::CLAIM => Chart::SCHEME_INCOME,
            default => Chart::paidThrough('account', Cheque::withoutGlobalScopes()->whereKey($document->source_id)->value('shop_account_id')),
        };

        $lines = [
            Line::debit(Chart::VENDOR_CREDITS, $amount, $date, null, 'vendor', $vendor),
            Line::credit($from, $amount, $date),
        ];

        foreach (VendorCreditRefund::withoutGlobalScopes()->where('vendor_credit_id', $document->id)->get() as $refund) {
            $lines[] = Line::debit(Chart::paidThrough($refund->payment_method, $refund->shop_account_id), $refund->amount, $refund->refund_date);
            $lines[] = Line::credit(Chart::VENDOR_CREDITS, $refund->amount, $refund->refund_date, null, 'vendor', $vendor);
        }

        return $lines;
    }

    public function description(Model $document): string
    {
        return 'Vendor credit: '.$document->sourceLabel().($document->status === 'cancelled' ? ' (cancelled)' : '');
    }
}
