<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleReturn;
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

    public function downloadCreditNote(SaleReturn $return): StreamedResponse
    {
        $return->loadMissing(['items', 'sale.shop', 'sale.customer', 'customer', 'user']);
        $output = Pdf::loadView('pdf.credit-note', ['return' => $return])->setPaper('a5')->output();

        return response()->streamDownload(fn () => print ($output), $return->number().'.pdf');
    }

    public function render(Sale $sale): string
    {
        $sale->loadMissing(['items', 'shop', 'user', 'payments']);

        return Pdf::loadView('pdf.invoice', ['sale' => $sale])->setPaper('a5')->output();
    }
}
