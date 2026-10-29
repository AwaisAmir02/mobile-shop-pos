<?php

namespace App\Enums;

enum WhatsAppOrderStatus: string
{
    case Collecting = 'collecting';
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Collecting => 'In cart',
            self::Pending => 'Pending review',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
        };
    }
}
