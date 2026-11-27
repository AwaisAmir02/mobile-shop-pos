<?php

namespace App\Enums;

/** A booking or a walk-in, from arriving to being billed. */
enum AppointmentStatus: string
{
    case Booked = 'booked';
    /** Arrived, or a walk-in in the queue. */
    case Waiting = 'waiting';
    case InService = 'in_service';
    /** Billed: the sale is on the appointment. */
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::Waiting => 'Waiting',
            self::InService => 'In service',
            self::Completed => 'Completed',
            self::NoShow => 'No-show',
            self::Cancelled => 'Cancelled',
        };
    }

    /** Still on the books: takes a staff member's time and can be billed. */
    public function isOpen(): bool
    {
        return in_array($this, [self::Booked, self::Waiting, self::InService], true);
    }

    /** @return list<string> */
    public static function openValues(): array
    {
        return [self::Booked->value, self::Waiting->value, self::InService->value];
    }
}
