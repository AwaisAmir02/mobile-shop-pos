<?php

namespace App\Services;

use App\Models\BillPayment;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillPaymentReceiptPdfService
{
    public function download(BillPayment $billPayment): StreamedResponse
    {
        $billPayment->loadMissing(['shop', 'user', 'customer', 'billCategory', 'billProvider']);

        $pdf = Pdf::loadView('pdf.bill-payment-receipt', ['payment' => $billPayment])->setPaper('a6');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $billPayment->receiptNumber().'.pdf'
        );
    }
}
