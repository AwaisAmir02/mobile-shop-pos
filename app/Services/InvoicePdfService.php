<?php

namespace App\Services;

use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdfService
{
    public function download(Sale $sale): StreamedResponse
    {
        $sale->loadMissing(['items', 'shop', 'user']);

        $pdf = Pdf::loadView('pdf.invoice', ['sale' => $sale])->setPaper('a5');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $sale->invoiceNumber().'.pdf'
        );
    }
}
