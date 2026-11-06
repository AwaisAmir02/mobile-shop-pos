<?php

namespace App\Services;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PayslipPdfService
{
    public function download(Payslip $payslip): StreamedResponse
    {
        $output = $this->render($payslip);

        return response()->streamDownload(fn () => print ($output), $payslip->number().'.pdf');
    }

    public function render(Payslip $payslip): string
    {
        $payslip->loadMissing(['lines', 'shop']);

        return Pdf::loadView('pdf.payslip', ['payslip' => $payslip])->setPaper('a5')->output();
    }
}
