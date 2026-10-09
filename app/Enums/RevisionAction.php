<?php

namespace App\Enums;

enum RevisionAction: string
{
    case Edited = 'edited';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Edited => 'Edited',
            self::Cancelled => 'Cancelled',
        };
    }
}
