<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use Illuminate\Database\Eloquent\Model;

class BillPaymentBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function passThrough(Model $document): float
    {
        return (float) $document->amount;
    }

    protected function label(Model $document): string
    {
        return 'Bill payment '.$document->receiptNumber();
    }
}
