<?php

namespace App\Services;

use App\Models\GiftVoucher;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A printable gift voucher with its code. */
class GiftVoucherPdfService
{
    public function download(GiftVoucher $voucher): StreamedResponse
    {
        $output = $this->render($voucher);

        return response()->streamDownload(fn () => print ($output), 'gift-voucher-'.$voucher->code.'.pdf');
    }

    public function render(GiftVoucher $voucher): string
    {
        $voucher->loadMissing(['shop', 'buyer']);

        return Pdf::loadView('pdf.gift-voucher', ['voucher' => $voucher])->setPaper('a6', 'landscape')->output();
    }
}
