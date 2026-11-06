<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Enums\WalletLoadDirection;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * Cash in is a pass-through like a balance load. Cash out runs the other way:
 * the customer sends the total to the shop's wallet and the till hands back the
 * cash, keeping the fee. Nothing is owed either way, which is why the dues
 * statement leaves cash-outs out.
 */
class WalletLoadBuilder extends CounterServiceBuilder
{
    protected function incomeAccount(): string
    {
        return Chart::SERVICE_INCOME;
    }

    protected function income(Model $document): float
    {
        return round((float) $document->fee - (float) $document->discount, 2);
    }

    protected function passThrough(Model $document): float
    {
        return round((float) $document->total - $this->income($document), 2);
    }

    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled || $document->direction !== WalletLoadDirection::CashOut) {
            return parent::lines($document);
        }

        $date = $document->created_at;
        $branch = $document->branch_id;

        return [
            Line::debit(Chart::CLEARING, $document->total, $date, $branch),
            Line::credit(Chart::CASH, $this->passThrough($document), $date, $branch),
            Line::credit(Chart::SERVICE_INCOME, $this->income($document), $date, $branch),
        ];
    }

    protected function label(Model $document): string
    {
        return 'Wallet '.str_replace('_', ' ', $document->direction->value).': '.$document->provider;
    }
}
