<?php

namespace App\Enums;

/** Who honours a warranty: the brand (claims go to the brand's centre) or the shop itself. */
enum WarrantyType: string
{
    case Brand = 'brand';
    case Shop = 'shop';

    public function label(): string
    {
        return match ($this) {
            self::Brand => 'Brand warranty',
            self::Shop => 'Shop warranty',
        };
    }
}
