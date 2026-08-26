<?php

namespace App\Services;

use App\Models\WalletLoad;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WalletLoadReceiptPdfService
{
    public function download(WalletLoad $walletLoad): StreamedResponse
    {
        $walletLoad->loadMissing(['shop', 'user']);

        $pdf = Pdf::loadView('pdf.wallet-load-receipt', ['load' => $walletLoad])->setPaper('a6');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $walletLoad->receiptNumber().'.pdf'
        );
    }
}
