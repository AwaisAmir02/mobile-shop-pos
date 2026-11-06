<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use Illuminate\Database\Eloquent\Model;

class ServicePaymentBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function total(Model $document): float
    {
        return (float) $document->amount;
    }

    protected function label(Model $document): string
    {
        return 'Service '.$document->receiptNumber();
    }
}
