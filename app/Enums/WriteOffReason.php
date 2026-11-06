<?php

namespace App\Enums;

enum WriteOffReason: string
{
    case Expired = 'expired';
    case Damaged = 'damaged';
    case Lost = 'lost';
    case CountCorrection = 'count_correction';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Expired => 'Expired',
            self::Damaged => 'Damaged',
            self::Lost => 'Lost or stolen',
            self::CountCorrection => 'Count correction',
            self::Other => 'Other',
        };
    }
}
