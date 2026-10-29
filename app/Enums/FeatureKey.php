<?php

namespace App\Enums;

enum FeatureKey: string
{
    case WhatsApp = 'whatsapp';
    case Social = 'social';
    case Ads = 'ads';
    case Import = 'import';
    case Barcode = 'barcode';
    case Branding = 'branding';
    case Services = 'services';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp ordering & messages',
            self::Social => 'Facebook & Instagram posting',
            self::Ads => 'Meta ads',
            self::Import => 'Excel product import',
            self::Barcode => 'Barcodes',
            self::Branding => 'Invoice branding',
            self::Services => 'Services module',
        };
    }

    public function column(): string
    {
        return $this->value.'_enabled';
    }
}
