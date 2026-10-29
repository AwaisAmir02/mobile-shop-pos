<?php

namespace App\Enums;

enum AddonBilling: string
{
    case OneOff = 'one_off';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::OneOff => 'One-off, until the plan renews',
            self::Monthly => 'Every month',
            self::Yearly => 'Every year',
        };
    }

    public function suffix(): string
    {
        return match ($this) {
            self::OneOff => 'one-off',
            self::Monthly => '/ month',
            self::Yearly => '/ year',
        };
    }

    public function months(): int
    {
        return $this === self::Yearly ? 12 : 1;
    }
}
