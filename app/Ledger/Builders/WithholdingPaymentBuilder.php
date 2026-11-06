<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

class WithholdingPaymentBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        return [
            Line::debit(Chart::WITHHOLDINGS, $document->amount, $document->paid_on),
            Line::credit(Chart::paidThrough($document->payment_method, $document->shop_account_id, Chart::CASH), $document->amount, $document->paid_on),
        ];
    }

    public function description(Model $document): string
    {
        return 'Withheld salary paid over'.($document->note ? ': '.$document->note : '');
    }
}
