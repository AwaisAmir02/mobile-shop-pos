<?php

namespace App\Enums;

/** A Daraz Seller Center file brought in (O6). */
enum MarketplaceImportKind: string
{
    case Orders = 'orders';
    case Statement = 'statement';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Orders',
            self::Statement => 'Payout statement',
        };
    }
}
