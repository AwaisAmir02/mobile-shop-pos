<?php

namespace App\Enums;

enum CheckInSource: string
{
    case Desk = 'desk';
    case Device = 'device';
    /** The QR on a member's card or a staff badge, scanned at the desk. */
    case Qr = 'qr';

    public function label(): string
    {
        return match ($this) {
            self::Desk => 'At the desk',
            self::Device => 'Fingerprint',
            self::Qr => 'QR card',
        };
    }
}
