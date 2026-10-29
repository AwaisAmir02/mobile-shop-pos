<?php

namespace App\Enums;

enum PostKind: string
{
    case Product = 'product';
    case Review = 'review';
    case QrCode = 'qr';

    public function label(): string
    {
        return match ($this) {
            self::Product => 'Product',
            self::Review => 'Customer review',
            self::QrCode => 'In-shop QR code',
        };
    }
}
