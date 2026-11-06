<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use Illuminate\Database\Eloquent\Model;

class RepairBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::REPAIR_INCOME;
    }

    protected function label(Model $document): string
    {
        return 'Repair '.$document->receiptNumber();
    }
}
