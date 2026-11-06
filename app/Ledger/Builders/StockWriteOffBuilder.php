<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

class StockWriteOffBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        return [
            Line::debit(Chart::WRITE_OFFS, $document->total_cost ?? 0, $document->written_off_on, $document->branch_id),
            Line::credit(Chart::INVENTORY, $document->total_cost ?? 0, $document->written_off_on, $document->branch_id),
        ];
    }

    public function description(Model $document): string
    {
        return 'Write-off: '.$document->product_name;
    }
}
