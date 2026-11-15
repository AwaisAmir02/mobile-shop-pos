<?php

namespace App\Couriers;

final class BookingResult
{
    /** @param array<string, mixed> $raw */
    public function __construct(
        public readonly string $trackingNumber,
        public readonly ?string $labelUrl = null,
        public readonly array $raw = [],
    ) {
    }
}
