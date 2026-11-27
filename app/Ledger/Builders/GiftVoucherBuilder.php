<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * A gift voucher sold: money in, owed to whoever holds the code (Gift
 * Vouchers Outstanding). Spending it posts with the sale's "gift_voucher"
 * payment. An unspent voucher that expires stays owed until the accountant
 * decides when to take it to income.
 */
class GiftVoucherBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        return [
            Line::debit(Chart::paidThrough($document->payment_method, $document->shop_account_id), $document->amount, $document->sold_on, $document->branch_id),
            Line::credit(Chart::GIFT_VOUCHERS, $document->amount, $document->sold_on, $document->branch_id),
        ];
    }

    public function description(Model $document): string
    {
        return $document->status === RecordStatus::Cancelled ? "Gift voucher {$document->code} cancelled" : "Gift voucher {$document->code} sold";
    }
}
