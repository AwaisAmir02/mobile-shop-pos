<?php

namespace App\Enums;

/**
 * Where a Daraz order item stands, as Seller Center's order export writes it
 * (pending, ready_to_ship, shipped, delivered, failed, returned, canceled…).
 * Grouped into what the parcel here needs to know.
 */
enum DarazOrderStatus: string
{
    case Open = 'open';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public static function fromExport(string $status): self
    {
        $status = strtolower(trim(str_replace(['_', '-'], ' ', $status)));

        return match (true) {
            str_contains($status, 'cancel') => self::Cancelled,
            $status === 'delivered' || str_contains($status, 'delivered') && ! str_contains($status, 'fail') => self::Delivered,
            str_contains($status, 'return') || str_contains($status, 'fail') || str_contains($status, 'lost') || str_contains($status, 'damaged') => self::Returned,
            default => self::Open,
        };
    }

    /** The parcel status it moves a Daraz parcel to, if any. */
    public function shipmentStatus(): ?ShipmentStatus
    {
        return match ($this) {
            self::Delivered => ShipmentStatus::Delivered,
            self::Returned => ShipmentStatus::Returned,
            default => null,
        };
    }

    /** Several items of one order: the furthest along decides the parcel. */
    public function rank(): int
    {
        return match ($this) {
            self::Cancelled => 0,
            self::Open => 1,
            self::Returned => 2,
            self::Delivered => 3,
        };
    }
}
