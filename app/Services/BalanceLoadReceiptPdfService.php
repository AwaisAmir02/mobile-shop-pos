<?php

namespace App\Services;

use App\Models\BalanceLoad;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BalanceLoadReceiptPdfService
{
    public function download(BalanceLoad $balanceLoad): StreamedResponse
    {
        $balanceLoad->loadMissing(['shop', 'user']);

        $pdf = Pdf::loadView('pdf.balance-load-receipt', ['load' => $balanceLoad])->setPaper('a6');

        return response()->streamDownload(
            fn () => print($pdf->output()),
            $balanceLoad->receiptNumber().'.pdf'
        );
    }
}
