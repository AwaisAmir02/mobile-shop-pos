<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Models\StoreCreditEntry;
use Illuminate\Database\Eloquent\Model;

/**
 * Store credit leaving the customer other than on a sale. A cash-out pays the
 * credit owed in money, from the cash or account it was handed over from; an
 * expiry turns what was left into income. Issues post with their return, and
 * spends with their sale's Store credit payment, so those want no lines here.
 */
class StoreCreditBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled || ! in_array($document->type, [StoreCreditEntry::CASH_OUT, StoreCreditEntry::EXPIRE], true)) {
            return [];
        }

        $amount = abs((float) $document->amount);
        $date = $document->entry_date;
        $branch = $document->branch_id;

        return [
            Line::debit(Chart::CUSTOMER_CREDIT, $amount, $date, $branch, 'customer', $document->customer_id),
            Line::credit($document->type === StoreCreditEntry::EXPIRE ? Chart::EXPIRED_CREDIT : Chart::paidThrough($document->payment_method, $document->shop_account_id), $amount, $date, $branch),
        ];
    }

    public function description(Model $document): string
    {
        $what = $document->type === StoreCreditEntry::EXPIRE ? 'Store credit expired' : 'Store credit cashed out';

        return $document->status === RecordStatus::Cancelled ? "{$what} {$document->number()} cancelled" : "{$what} {$document->number()}";
    }
}
