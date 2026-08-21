<?php

namespace App\Enums;

enum SimType: string
{
    case Prepaid = 'prepaid';
    case Postpaid = 'postpaid';

    public function label(): string
    {
        return match ($this) {
            self::Prepaid => 'Prepaid',
            self::Postpaid => 'Postpaid',
        };
    }
}
