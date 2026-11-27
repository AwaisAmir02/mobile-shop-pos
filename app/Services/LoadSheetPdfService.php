<?php

namespace App\Services;

use App\Actions\LoadSheets;
use App\Models\LoadSheet;
use App\Support\CustomerDuesStatement;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** A van's load sheet (W7): what to load, and the stops in visiting order with what each owes. */
class LoadSheetPdfService
{
    public function download(LoadSheet $sheet): StreamedResponse
    {
        $output = $this->render($sheet);

        return response()->streamDownload(fn () => print ($output), $sheet->number().'.pdf');
    }

    public function render(LoadSheet $sheet): string
    {
        $stops = LoadSheets::stops($sheet);

        return Pdf::loadView('pdf.load-sheet', [
            'sheet' => $sheet->load(['deliveryRoute', 'salesman']),
            'loading' => LoadSheets::loadingList($sheet),
            'stops' => $stops,
            'owed' => $stops->mapWithKeys(fn ($order) => [$order->id => $order->customer ? CustomerDuesStatement::for($order->customer)->total() : 0.0])->all(),
            'shop' => $sheet->shop,
        ])->setPaper('a4')->output();
    }
}
