<?php

namespace App\Couriers;

use App\Models\CourierConnection;
use App\Models\Shipment;

/**
 * One courier's API. Adding a courier means one class implementing this and
 * one line in CourierKey::providerClass(); nothing else changes.
 *
 * Every call uses the shop's own credentials from its CourierConnection, and
 * throws CourierException with a message a shop owner can act on.
 */
interface CourierProvider
{
    /**
     * The fields the shop fills in to connect, in order.
     *
     * @return list<array{key: string, label: string, help: string, secret: bool, options?: array<string, string>}>
     */
    public function credentialFields(): array;

    /** Where the shop finds those credentials, in one or two sentences. */
    public function credentialHelp(): string;

    /**
     * Checks the credentials work. Returns settings to keep on the connection
     * (e.g. the courier's list of the shop's pickup addresses).
     *
     * @return array<string, mixed>
     */
    public function verify(CourierConnection $connection): array;

    /**
     * Cities the courier delivers to, as value => name. The value is what
     * book() needs (an ID for some couriers, the name for others).
     *
     * @return array<string, string>
     */
    public function cities(CourierConnection $connection): array;

    public function book(CourierConnection $connection, BookingRequest $request): BookingResult;

    public function track(CourierConnection $connection, Shipment $shipment): TrackingResult;

    public function label(CourierConnection $connection, Shipment $shipment): LabelResult;

    public function cancel(CourierConnection $connection, Shipment $shipment): void;
}
