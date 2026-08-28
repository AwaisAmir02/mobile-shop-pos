<?php

namespace App\Services;

use App\Models\SimSale;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SimSaleReceiptPdfService
{
    public function download(SimSale $simSale): StreamedResponse
    {
        $simSale->loadMissing(['shop', 'user', 'customer']);

        $pdf = Pdf::loadView('pdf.sim-sale-receipt', ['sale' => $simSale])->setPaper('a6');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $simSale->receiptNumber().'.pdf'
        );
    }
}
