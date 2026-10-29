<?php

namespace App\Services\Marketing;

use App\Models\MarketingPost;
use App\Models\Shop;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

class QrCodeService
{
    public const MODE_CHAT = 'chat';

    public const MODE_CATALOG = 'catalog';

    public static function prefillText(string $mode, string $refTag): string
    {
        return ($mode === self::MODE_CATALOG ? 'CATALOG ' : 'Hi ').$refTag;
    }

    public function link(Shop $shop, MarketingPost $qr): ?string
    {
        return PostComposer::whatsappLink($shop, (string) $qr->caption);
    }

    public function png(string $data, int $scale = 12): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'outputBase64' => false,
            'quietzoneSize' => 2,
        ]);

        return (new QRCode($options))->render($data);
    }

    public function dataUri(string $data, int $scale = 8): string
    {
        return 'data:image/png;base64,'.base64_encode($this->png($data, $scale));
    }

    public function posterPdf(Shop $shop, MarketingPost $qr): string
    {
        $link = $this->link($shop, $qr) ?? throw new \RuntimeException('Connect WhatsApp or add a shop phone number first.');

        return Pdf::loadView('pdf.qr-poster', [
            'shop' => $shop,
            'qr' => $qr,
            'qrImage' => $this->dataUri($link, 14),
            'isCatalog' => str_starts_with((string) $qr->caption, 'CATALOG'),
            'number' => PostComposer::prettyNumber(PostComposer::whatsappNumber($shop)),
        ])->setPaper('a5')->output();
    }
}
