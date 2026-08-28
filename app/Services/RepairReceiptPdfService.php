<?php

namespace App\Services;

use App\Models\Repair;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairReceiptPdfService
{
    public function download(Repair $repair): StreamedResponse
    {
        $repair->loadMissing(['shop', 'user', 'customer']);

        $pdf = Pdf::loadView('pdf.repair-receipt', ['repair' => $repair])->setPaper('a6');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $repair->receiptNumber().'.pdf'
        );
    }
}
