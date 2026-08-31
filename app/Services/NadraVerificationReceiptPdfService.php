<?php

namespace App\Services;

use App\Models\NadraVerification;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NadraVerificationReceiptPdfService
{
    public function download(NadraVerification $verification): StreamedResponse
    {
        $verification->loadMissing(['shop', 'user', 'customer']);

        $pdf = Pdf::loadView('pdf.nadra-verification-receipt', ['verification' => $verification])->setPaper('a6');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $verification->receiptNumber().'.pdf'
        );
    }
}
