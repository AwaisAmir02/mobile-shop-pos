<?php

namespace App\Enums;

/** How a membership plan runs out: by the calendar (a gym month) or by sessions used (10 facials). */
enum MembershipKind: string
{
    case Time = 'time';
    case Sessions = 'sessions';

    public function label(): string
    {
        return match ($this) {
            self::Time => 'Membership (by time)',
            self::Sessions => 'Package (by sessions)',
        };
    }
}
