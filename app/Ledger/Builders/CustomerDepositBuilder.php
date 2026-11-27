<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * A deposit taken ahead of a booking or job: money in, owed to that customer
 * as service (Customer Deposits). A refund pays it back; what the shop keeps
 * (a no-show) becomes income. What is used on a bill posts with that sale's
 * "deposit" payment, so it wants nothing here.
 */
class CustomerDepositBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $branch = $document->branch_id;
        $customer = $document->customer_id;
        $lines = [
            Line::debit(Chart::paidThrough($document->payment_method, $document->shop_account_id), $document->amount, $document->received_on, $branch),
            Line::credit(Chart::CUSTOMER_DEPOSITS, $document->amount, $document->received_on, $branch, 'customer', $customer),
        ];

        if ((float) $document->refunded > 0) {
            $lines[] = Line::debit(Chart::CUSTOMER_DEPOSITS, $document->refunded, $document->refunded_on, $branch, 'customer', $customer);
            $lines[] = Line::credit(Chart::paidThrough($document->refund_method, $document->refund_account_id), $document->refunded, $document->refunded_on, $branch);
        }

        if ((float) $document->forfeited > 0) {
            $lines[] = Line::debit(Chart::CUSTOMER_DEPOSITS, $document->forfeited, $document->forfeited_on, $branch, 'customer', $customer);
            $lines[] = Line::credit(Chart::FORFEITED_DEPOSITS, $document->forfeited, $document->forfeited_on, $branch);
        }

        return $lines;
    }

    public function description(Model $document): string
    {
        return $document->status === RecordStatus::Cancelled ? "Deposit {$document->number()} cancelled" : "Deposit {$document->number()}";
    }
}
