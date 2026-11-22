<?php

namespace App\Enums;

/** The state of a used phone when the shop buys it. */
enum BuybackCondition: string
{
    case LikeNew = 'like_new';
    case Good = 'good';
    case Fair = 'fair';
    case Faulty = 'faulty';

    public function label(): string
    {
        return match ($this) {
            self::LikeNew => 'Like new',
            self::Good => 'Good (light marks)',
            self::Fair => 'Fair (visible wear)',
            self::Faulty => 'Faulty / for parts',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::LikeNew => 'success',
            self::Good => 'brand',
            self::Fair => 'warning',
            self::Faulty => 'danger',
        };
    }
}
