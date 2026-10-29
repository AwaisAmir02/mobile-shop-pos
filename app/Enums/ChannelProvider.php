<?php

namespace App\Enums;

enum ChannelProvider: string
{
    case WhatsApp = 'whatsapp';
    case Facebook = 'facebook';
    case Instagram = 'instagram';
    case MetaAds = 'meta_ads';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::Facebook => 'Facebook Page',
            self::Instagram => 'Instagram',
            self::MetaAds => 'Meta ad account',
        };
    }
}
