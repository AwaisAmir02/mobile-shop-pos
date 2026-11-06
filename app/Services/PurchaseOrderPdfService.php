<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseOrderPdfService
{
    public function download(PurchaseOrder $order): StreamedResponse
    {
        $output = $this->render($order);

        return response()->streamDownload(fn () => print ($output), $order->number().'.pdf');
    }

    public function render(PurchaseOrder $order): string
    {
        $order->loadMissing(['lines', 'vendor', 'shop', 'user']);

        return Pdf::loadView('pdf.purchase-order', ['order' => $order])->setPaper('a5')->output();
    }
}
