<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Open = 'open';
    case PartiallyReceived = 'partially_received';
    case FullyReceived = 'fully_received';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::PartiallyReceived => 'Partly received',
            self::FullyReceived => 'Fully received',
            self::Closed => 'Closed short',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Open => 'neutral',
            self::PartiallyReceived => 'warning',
            self::FullyReceived => 'success',
            self::Closed => 'neutral',
            self::Cancelled => 'danger',
        };
    }

    /** Still expecting goods, so a product's unit must not change underneath it. */
    public function locksProductUnit(): bool
    {
        return in_array($this, [self::Open, self::PartiallyReceived, self::FullyReceived], true);
    }
}
