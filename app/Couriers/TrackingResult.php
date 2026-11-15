<?php

namespace App\Couriers;

use App\Enums\ShipmentStatus;

final class TrackingResult
{
    /**
     * @param  list<array{at: ?string, status: string, note: ?string}>  $events  oldest first
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public readonly ShipmentStatus $status,
        public readonly string $courierStatus,
        public readonly array $events = [],
        public readonly array $raw = [],
    ) {
    }
}
