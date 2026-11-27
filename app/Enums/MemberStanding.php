<?php

namespace App\Enums;

/** Where a customer's membership stands on a day: what the check-in desk shows. */
enum MemberStanding: string
{
    case Active = 'active';
    case Frozen = 'frozen';
    case Expired = 'expired';
    case UsedUp = 'used_up';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::Frozen => 'Frozen',
            self::Expired => 'Expired',
            self::UsedUp => 'Sessions used up',
            self::None => 'No membership',
        };
    }

    /** Whether the desk should stop and look before letting them in. */
    public function needsAttention(): bool
    {
        return $this !== self::Active;
    }
}
