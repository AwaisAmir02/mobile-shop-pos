<?php

namespace App\Services;

use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductLabelPdfService
{
    public const LAYOUT_ROLL = 'roll';

    public const LAYOUT_A4 = 'a4';

    public const A4_COLUMNS = 3;

    public const A4_ROWS_PER_PAGE = 8;

    public function download(Product $product, int $copies, string $layout, bool $showPrice, string $shopName): StreamedResponse
    {
        $pdf = Pdf::loadView('pdf.product-label', [
            'layout' => $layout,
            'copies' => $copies,
            'columns' => self::A4_COLUMNS,
            'rowsPerPage' => self::A4_ROWS_PER_PAGE,
            'label' => [
                'shopName' => $shopName,
                'name' => $product->name,
                'barcode' => $product->barcode,
                'barcodeImage' => BarcodeService::imageDataUri($product->barcode),
                'price' => $showPrice ? 'Rs '.number_format($product->price, 2) : null,
            ],
        ])->setPaper($layout === self::LAYOUT_ROLL ? [0, 0, 141.73, 85.04] : 'a4');

        $filename = 'label-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $product->barcode).'.pdf';

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
