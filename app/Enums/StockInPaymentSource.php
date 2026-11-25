<?php

namespace App\Enums;

enum StockInPaymentSource: string
{
    case Initial = 'initial';
    case Payment = 'payment';
    case MarkPaid = 'mark_paid';
    case Backfill = 'backfill';
    case InvoicePayment = 'invoice_payment';
    case Cheque = 'cheque';
    case VendorCredit = 'vendor_credit';
    case Contra = 'contra';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Paid at purchase',
            self::Payment => 'Payment made',
            self::MarkPaid => 'Marked as paid',
            self::Backfill => 'Paid before payment dates were tracked',
            self::InvoicePayment => 'Paid on vendor invoice',
            self::Cheque => 'Paid by cheque',
            self::VendorCredit => 'Vendor credit (debit note or claim)',
            self::Contra => 'Contra set-off',
        };
    }
}
