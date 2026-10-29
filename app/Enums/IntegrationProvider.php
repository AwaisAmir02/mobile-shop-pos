<?php

namespace App\Enums;

enum IntegrationProvider: string
{
    case WhatsApp = 'whatsapp';
    case Meta = 'meta';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp Business Platform',
            self::Meta => 'Meta (Facebook & Instagram)',
        };
    }
}
