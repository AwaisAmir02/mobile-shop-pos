<?php

namespace App\Enums;

enum SaleSource: string
{
    case InStore = 'in_store';
    case WhatsApp = 'whatsapp';
    case Social = 'social';
    case Online = 'online';
    /** O6: an order imported from Daraz Seller Center. */
    case Daraz = 'daraz';

    public function label(): string
    {
        return match ($this) {
            self::InStore => 'In-store',
            self::WhatsApp => 'WhatsApp',
            self::Social => 'Social',
            self::Online => 'Online store',
            self::Daraz => 'Daraz',
        };
    }

    /**
     * Listed on reports only once a shop has sales from it, so a shop that has
     * never sold on Daraz sees the same rows it always did.
     */
    public function listedOnlyWhenUsed(): bool
    {
        return $this === self::Daraz;
    }
}
