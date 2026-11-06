<?php

namespace App\Ledger\Builders;

use App\Ledger\ChartOfAccounts as Chart;
use Illuminate\Database\Eloquent\Model;

class NadraVerificationBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function label(Model $document): string
    {
        return 'NADRA verification';
    }
}
