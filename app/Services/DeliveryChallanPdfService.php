<?php

namespace App\Services;

use App\Models\SalesOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** The delivery challan that goes with a booked order on the van (W7). */
class DeliveryChallanPdfService
{
    public function download(SalesOrder $order): StreamedResponse
    {
        $output = $this->render($order);

        return response()->streamDownload(fn () => print ($output), 'Challan-'.$order->number().'.pdf');
    }

    public function render(SalesOrder $order): string
    {
        $order = $order->fresh(['lines.product', 'customer', 'booker', 'loadSheet', 'sale.items', 'shop']);

        return Pdf::loadView('pdf.delivery-challan', ['order' => $order, 'shop' => $order->shop])->setPaper('a5')->output();
    }
}
