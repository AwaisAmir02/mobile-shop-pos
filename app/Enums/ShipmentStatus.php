<?php

namespace App\Enums;

/**
 * Our own status for a booked parcel. Each courier's wording is mapped onto
 * these by its provider (CourierProvider::track), and the courier's own words
 * are kept on the shipment beside it.
 */
enum ShipmentStatus: string
{
    case Booked = 'booked';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Booked',
            self::InTransit => 'In transit',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
            self::Returning => 'Returning to you',
            self::Returned => 'Returned to you',
            self::Cancelled => 'Cancelled',
        };
    }

    public function variant(): string
    {
        return match ($this) {
            self::Delivered => 'success',
            self::Returning, self::Returned => 'danger',
            self::OutForDelivery => 'warning',
            self::Cancelled => 'neutral',
            default => 'info',
        };
    }

    /** No more status changes are expected from the courier. */
    public function isFinal(): bool
    {
        return in_array($this, [self::Delivered, self::Returned, self::Cancelled], true);
    }

    /** @return array<int, self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status) => ! $status->isFinal()));
    }
}
