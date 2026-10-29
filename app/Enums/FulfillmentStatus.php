<?php

namespace App\Enums;

enum FulfillmentStatus: string
{
    case Packed = 'packed';
    case ReadyForPickup = 'ready_for_pickup';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';

    public function label(): string
    {
        return match ($this) {
            self::Packed => 'Packed',
            self::ReadyForPickup => 'Ready for pickup',
            self::OutForDelivery => 'Out for delivery',
            self::Delivered => 'Delivered',
        };
    }

    public function messageKey(): string
    {
        return 'fulfillment.'.$this->value;
    }

    public function step(): int
    {
        return match ($this) {
            self::Packed => 1,
            self::ReadyForPickup, self::OutForDelivery => 2,
            self::Delivered => 3,
        };
    }
}
