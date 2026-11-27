<?php

namespace App\Services;

use App\Models\SchemeClaim;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The statement sent to a company with a scheme claim (W9): every free piece given. */
class SchemeClaimPdfService
{
    public function download(SchemeClaim $claim): StreamedResponse
    {
        $output = $this->render($claim);

        return response()->streamDownload(fn () => print ($output), $claim->number().'.pdf');
    }

    public function render(SchemeClaim $claim): string
    {
        $claim->loadMissing(['vendor', 'redemptions.scheme', 'redemptions.sale.customer', 'shop']);

        return Pdf::loadView('pdf.scheme-claim', ['claim' => $claim, 'shop' => $claim->shop])->setPaper('a4')->output();
    }
}
