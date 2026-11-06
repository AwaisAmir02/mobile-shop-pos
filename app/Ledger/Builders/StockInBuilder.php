<?php

namespace App\Ledger\Builders;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Ledger\EntryBuilder;
use App\Ledger\Line;
use App\Models\StockInPayment;
use App\Models\VendorInvoice;
use App\Models\VendorInvoiceLine;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;

/**
 * A batch: plain Stock In or from a goods receipt, they're the same rows. What
 * it cost at receipt posts on its date. A vendor invoice's correction posts on
 * the day it was approved: the unsold share to Inventory, the share already
 * sold to Adjustments, because those units keep their old cost in COGS.
 */
class StockInBuilder implements EntryBuilder
{
    public function lines(Model $document): array
    {
        $vendor = $document->vendor_id;
        $branch = $document->branch_id;
        $lines = [];
        $payments = StockInPayment::withoutGlobalScopes()->where('stock_in_id', $document->id)->get();

        // No record of a refund, so a payment on a cancelled batch is the one genuinely unclear case: it's parked.
        if ($document->status === RecordStatus::Cancelled) {
            foreach ($payments as $payment) {
                $lines[] = Line::debit(Chart::CANCELLED_UNCONFIRMED, $payment->amount, $payment->payment_date, $branch, 'vendor', $vendor);
                $lines[] = Line::credit(Chart::NOT_CLASSIFIED, $payment->amount, $payment->payment_date, $branch);
            }

            return $lines;
        }

        $correction = VendorInvoiceLine::withoutGlobalScopes()->where('stock_in_id', $document->id)->whereNotNull('active_marker')->where('amended', true)->first();
        $total = (float) ($document->total_cost ?? 0);
        $received = $correction !== null ? (float) ($correction->previous_total_cost ?? 0) : $total;

        $lines[] = Line::debit(Chart::INVENTORY, $received, $document->stock_date, $branch);
        $lines[] = Line::credit(Chart::PAYABLE, $received, $document->stock_date, $branch, 'vendor', $vendor);

        if ($correction !== null) {
            $approvedOn = VendorInvoice::withoutGlobalScopes()->whereKey($correction->vendor_invoice_id)->value('reviewed_at');
            $change = round($total - $received, 2);
            $unsoldShare = (float) $document->quantity > 0 ? Quantity::plain($correction->unsold_quantity_at_approval ?? 0) / (float) $document->quantity : 0.0;
            $toInventory = round($change * $unsoldShare, 2);

            $lines[] = Line::credit(Chart::PAYABLE, $change, $approvedOn, $branch, 'vendor', $vendor);
            $lines[] = Line::debit(Chart::INVENTORY, $toInventory, $approvedOn, $branch);
            $lines[] = Line::debit(Chart::ADJUSTMENTS, round($change - $toInventory, 2), $approvedOn, $branch);
        }

        foreach ($payments as $payment) {
            $lines[] = Line::debit(Chart::PAYABLE, $payment->amount, $payment->payment_date, $branch, 'vendor', $vendor);
            $lines[] = Line::credit(Chart::NOT_CLASSIFIED, $payment->amount, $payment->payment_date, $branch);
        }

        return $lines;
    }

    public function description(Model $document): string
    {
        return 'Stock in: '.$document->product_name.($document->status === RecordStatus::Cancelled ? ' (cancelled)' : '');
    }
}
