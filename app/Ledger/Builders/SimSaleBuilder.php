<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use Illuminate\Database\Eloquent\Model;

class SimSaleBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function label(Model $document): string
    {
        return 'SIM sale: '.$document->network;
    }
}
