<?php

namespace App\Enums;

enum SaleSource: string
{
    case InStore = 'in_store';
    case WhatsApp = 'whatsapp';
    case Social = 'social';

    public function label(): string
    {
        return match ($this) {
            self::InStore => 'In-store',
            self::WhatsApp => 'WhatsApp',
            self::Social => 'Social',
        };
    }
}
