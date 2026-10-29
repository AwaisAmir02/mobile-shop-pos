<?php

namespace App\Services;

use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InvoicePdfService
{
    public function download(Sale $sale): StreamedResponse
    {
        $output = $this->render($sale);

        return response()->streamDownload(
            fn () => print ($output),
            $sale->invoiceNumber().'.pdf'
        );
    }

    public function render(Sale $sale): string
    {
        $sale->loadMissing(['items', 'shop', 'user', 'payments']);

        return Pdf::loadView('pdf.invoice', ['sale' => $sale])->setPaper('a5')->output();
    }
}
