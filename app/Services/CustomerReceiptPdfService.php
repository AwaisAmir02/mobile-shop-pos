<?php

namespace App\Services;

use App\Models\CustomerReceipt;
use App\Support\CustomerDuesStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A lump-sum payment received at the counter (W1), with what the customer owes after it. */
class CustomerReceiptPdfService
{
    public function download(CustomerReceipt $receipt): StreamedResponse
    {
        $output = $this->render($receipt);

        return response()->streamDownload(fn () => print ($output), $receipt->number().'.pdf');
    }

    public function render(CustomerReceipt $receipt): string
    {
        $receipt->loadMissing(['customer.shop', 'user', 'shopAccount']);

        return Pdf::loadView('pdf.customer-receipt', [
            'receipt' => $receipt,
            'balanceNow' => CustomerDuesStatement::for($receipt->customer)->total(),
        ])->setPaper('a6')->output();
    }
}
