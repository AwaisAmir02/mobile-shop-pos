<?php

namespace App\Services;

use App\Models\SubscriptionInvoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscriptionInvoicePdfService
{
    public function download(SubscriptionInvoice $invoice): StreamedResponse
    {
        $invoice->loadMissing('shop');

        $logoPath = public_path(config('branding.logo_horizontal'));
        $logo = is_file($logoPath)
            ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($logoPath))
            : null;

        $pdf = Pdf::loadView('pdf.subscription-invoice', [
            'invoice' => $invoice,
            'shop' => $invoice->shop,
            'logo' => $logo,
            'paymentInstructions' => config('branding.billing_payment_instructions'),
            'supportContact' => config('branding.billing_support_contact'),
        ])->setPaper('a4');

        return response()->streamDownload(
            fn () => print ($pdf->output()),
            $invoice->invoice_number.'.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
