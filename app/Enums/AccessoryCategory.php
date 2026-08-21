<?php

namespace App\Enums;

enum AccessoryCategory: string
{
    case CaseCover = 'case';
    case Charger = 'charger';
    case Cable = 'cable';
    case ScreenProtector = 'screen_protector';
    case Earphones = 'earphones';
    case PowerBank = 'power_bank';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::CaseCover => 'Case / Cover',
            self::Charger => 'Charger',
            self::Cable => 'Cable',
            self::ScreenProtector => 'Screen Protector',
            self::Earphones => 'Earphones',
            self::PowerBank => 'Power Bank',
            self::Other => 'Other',
        };
    }
}
