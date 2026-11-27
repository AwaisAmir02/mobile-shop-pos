<?php

namespace App\Services;

use App\Models\PurchaseReturn;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The debit note for goods sent back to a vendor (W10). */
class DebitNotePdfService
{
    public function download(PurchaseReturn $return): StreamedResponse
    {
        $output = $this->render($return);

        return response()->streamDownload(fn () => print ($output), $return->number().'.pdf');
    }

    public function render(PurchaseReturn $return): string
    {
        $return->loadMissing(['items', 'vendor', 'user', 'shop']);

        return Pdf::loadView('pdf.debit-note', ['return' => $return, 'shop' => $return->shop, 'credit' => $return->credit()])->setPaper('a5')->output();
    }
}
