<?php

namespace App\Enums;

/** Where a cheque is (W6). Only a cleared cheque has settled anything. */
enum ChequeStatus: string
{
    case Pending = 'pending';
    case Deposited = 'deposited';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
    case Cancelled = 'cancelled';

    /** Not cleared yet and not finished with: in hand, or given and not yet cashed. */
    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return [self::Pending, self::Deposited];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'In hand',
            self::Deposited => 'Deposited',
            self::Cleared => 'Cleared',
            self::Bounced => 'Bounced',
            self::Cancelled => 'Cancelled',
        };
    }
}
