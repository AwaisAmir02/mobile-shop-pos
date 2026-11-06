<?php

namespace App\Enums;

enum VendorInvoiceStatus: string
{
    case Flagged = 'flagged';
    case Approved = 'approved';
    case Disputed = 'disputed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Flagged => 'Needs review',
            self::Approved => 'Approved',
            self::Disputed => 'Disputed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Flagged => 'warning',
            self::Approved => 'success',
            self::Disputed, self::Cancelled => 'danger',
        };
    }
}
