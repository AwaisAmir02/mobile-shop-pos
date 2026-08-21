<?php

namespace App\Enums;

enum ProductType: string
{
    case Mobile = 'mobile';
    case Accessory = 'accessory';
    case Sim = 'sim';

    public function label(): string
    {
        return match ($this) {
            self::Mobile => 'Mobile Phone',
            self::Accessory => 'Accessory',
            self::Sim => 'SIM / eSIM',
        };
    }
}
