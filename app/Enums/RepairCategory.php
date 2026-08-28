<?php

namespace App\Enums;

enum RepairCategory: string
{
    case Phone = 'phone';
    case Accessory = 'accessory';

    public function label(): string
    {
        return match ($this) {
            self::Phone => 'Phone',
            self::Accessory => 'Accessory',
        };
    }
}
