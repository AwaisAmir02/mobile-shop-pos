<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use Illuminate\Database\Eloquent\Model;

/**
 * A courier payout (O4). Each parcel's collected cash already left Receivable
 * for Courier COD Clearing as a payment on its sale (SaleBuilder). The payout
 * empties that clearing account: the net into the account it was paid to, the
 * courier's delivery and return charges and other deductions to Courier &
 * Delivery Charges, and the tax it withheld to Tax Deducted by Couriers.
 * A cancelled payout wants no lines: its sale payments were reversed on their
 * own rows.
 */
class CourierSettlementBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        if ($document->status === RecordStatus::Cancelled) {
            return [];
        }

        $date = $document->settlement_date;
        $charges = round((float) $document->charges_total + (float) $document->other_deductions, 2);

        return [
            Line::debit(Chart::paidThrough($document->payment_method, $document->shop_account_id), $document->net_amount, $date),
            Line::debit(Chart::COURIER_CHARGES, $charges, $date),
            Line::debit(Chart::TAX_DEDUCTED, $document->tax_withheld, $date),
            Line::credit(Chart::COURIER_CLEARING, $document->cod_total, $date),
        ];
    }

    public function description(Model $document): string
    {
        return 'Courier payout '.$document->number().' from '.$document->courier->label().($document->status === RecordStatus::Cancelled ? ' (cancelled)' : '');
    }
}
