<?php

namespace App\Couriers;

/** What to book: everything a courier needs, already in our own terms. */
final class BookingRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly string $consigneeName,
        public readonly string $consigneePhone,
        public readonly string $consigneeAddress,
        public readonly string $city,
        public readonly string $cityName,
        public readonly float $codAmount,
        public readonly int $weightGrams,
        public readonly int $pieces,
        public readonly string $description,
        public readonly string $instructions = '',
        public readonly ?string $consigneeEmail = null,
    ) {
    }
}
