<?php

namespace App\Enums;

/** Where a "build my website" request stands (O9). The work itself is done by hand, outside the app. */
enum WebsiteRequestStatus: string
{
    case Submitted = 'submitted';
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::InProgress => 'In progress',
            self::Fulfilled => 'Website delivered',
            self::Declined => 'Declined',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::InProgress => 'info',
            self::Fulfilled => 'success',
            self::Declined => 'default',
        };
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Submitted, self::InProgress], true);
    }
}
