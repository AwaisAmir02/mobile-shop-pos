<?php

namespace App\Couriers\Providers;

use App\Couriers\BookingRequest;
use App\Couriers\BookingResult;
use App\Couriers\CourierException;
use App\Couriers\CourierProvider;
use App\Couriers\LabelResult;
use App\Couriers\TrackingResult;
use App\Enums\ShipmentStatus;
use App\Models\CourierConnection;
use App\Models\Shipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * PostEx merchant API (guide v4.1.9). The shop's API token comes from its own
 * PostEx merchant dashboard and goes in a "token" header. Every booking needs
 * one of the shop's PostEx pickup addresses (by its address code), which we
 * read when the shop connects. There is no public test server and no status
 * webhook, so bookings are real and status is polled.
 */
class PostExProvider implements CourierProvider
{
    public const BASE = 'https://api.postex.pk/services/integration/api/order/';

    public function credentialFields(): array
    {
        return [
            ['key' => 'token', 'label' => 'API Token', 'help' => 'From your PostEx merchant dashboard, under API integration.', 'secret' => true],
        ];
    }

    public function credentialHelp(): string
    {
        return 'You need a PostEx merchant account. In the PostEx merchant dashboard, generate an API token and paste it here. Add your pickup address in PostEx first; we\'ll ask which one to use.';
    }

    protected function request(CourierConnection $connection)
    {
        return Http::timeout(25)->withHeaders(['token' => (string) $connection->credential('token')])->acceptJson();
    }

    /** @return array<string, mixed> */
    protected function json(Response $response): array
    {
        $json = $response->json();

        if ($response->status() === 401 || $response->status() === 403) {
            throw new CourierException('PostEx didn\'t accept the API token. Generate a new one in your PostEx dashboard.');
        }

        if (! is_array($json)) {
            throw new CourierException('PostEx didn\'t accept the request (HTTP '.$response->status().').');
        }

        if (! $response->successful() || (string) ($json['statusCode'] ?? '200') !== '200') {
            throw new CourierException('PostEx said: '.($json['statusMessage'] ?? $json['message'] ?? $json['error'] ?? 'HTTP '.$response->status()));
        }

        return $json;
    }

    /** @return array<string, mixed> */
    protected function get(CourierConnection $connection, string $path, array $query = []): array
    {
        try {
            return $this->json($this->request($connection)->get(self::BASE.$path, $query));
        } catch (ConnectionException) {
            throw new CourierException('Couldn\'t reach PostEx. Try again in a minute.');
        }
    }

    public function verify(CourierConnection $connection): array
    {
        $addresses = collect($this->get($connection, 'v1/get-merchant-address')['dist'] ?? [])
            ->filter(fn ($row) => is_array($row) && ! empty($row['addressCode']))
            ->map(fn ($row) => [
                'code' => (string) $row['addressCode'],
                'label' => trim(($row['address'] ?? '').', '.($row['cityName'] ?? ''), ', '),
                'type' => (string) ($row['addressType'] ?? ''),
            ])
            ->values()
            ->all();

        if ($addresses === []) {
            throw new CourierException('Your PostEx account has no pickup address yet. Add one in the PostEx dashboard, then connect again.');
        }

        return ['pickup_addresses' => $addresses];
    }

    public function cities(CourierConnection $connection): array
    {
        return Cache::remember('couriers:postex:cities', now()->addDay(), function () use ($connection) {
            return collect($this->get($connection, 'v2/get-operational-city', ['operationalCityType' => 'delivery'])['dist'] ?? [])
                ->map(fn ($row) => is_array($row) ? ($row['operationalCityName'] ?? null) : $row)
                ->filter()
                ->mapWithKeys(fn ($name) => [(string) $name => (string) $name])
                ->sort()
                ->all();
        });
    }

    public function book(CourierConnection $connection, BookingRequest $request): BookingResult
    {
        $pickup = $connection->setting('pickup_address_code') ?? throw new CourierException('Choose your PostEx pickup address in Settings › Integrations › Couriers first.');

        try {
            $json = $this->json($this->request($connection)->post(self::BASE.'v3/create-order', [
                'orderRefNumber' => $request->reference,
                'invoicePayment' => (int) round($request->codAmount),
                'orderDetail' => mb_substr($request->description, 0, 250),
                'customerName' => $request->consigneeName,
                'customerPhone' => $request->consigneePhone,
                'deliveryAddress' => $request->consigneeAddress,
                'cityName' => $request->cityName,
                'invoiceDivision' => 0,
                'items' => max(1, $request->pieces),
                'orderType' => 'Normal',
                'transactionNotes' => $request->instructions,
                'pickupAddressCode' => $pickup,
            ]));
        } catch (ConnectionException) {
            throw new CourierException('Couldn\'t reach PostEx. Try again in a minute.');
        }

        $tracking = trim((string) ($json['dist']['trackingNumber'] ?? ''));

        if ($tracking === '') {
            throw new CourierException('PostEx accepted the order but sent back no tracking number. Check your PostEx dashboard before booking again.');
        }

        return new BookingResult($tracking, null, $json);
    }

    public function track(CourierConnection $connection, Shipment $shipment): TrackingResult
    {
        $dist = $this->get($connection, 'v1/track-order/'.rawurlencode($shipment->tracking_number))['dist'] ?? [];

        $events = collect($dist['transactionStatusHistory'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn ($row) => [
                'at' => $row['modifiedDatetime'] ?? $row['updatedAt'] ?? null,
                'status' => (string) ($row['transactionStatusMessage'] ?? ''),
                'note' => null,
            ])
            ->values()
            ->all();

        $courierStatus = (string) ($dist['transactionStatus'] ?? (end($events)['status'] ?? 'Booked'));

        return new TrackingResult(self::mapStatus($courierStatus), $courierStatus, $events, is_array($dist) ? $dist : []);
    }

    /** PostEx's wording onto ours ("Out For Return" before "Out For Delivery"). */
    public static function mapStatus(string $status): ShipmentStatus
    {
        $s = strtolower($status);

        return match (true) {
            str_contains($s, 'cancel'), str_contains($s, 'expired'), str_contains($s, 'un-assigned by me') => ShipmentStatus::Cancelled,
            str_contains($s, 'out for return'), str_contains($s, 'return in transit') => ShipmentStatus::Returning,
            str_contains($s, 'return') => ShipmentStatus::Returned,
            $s === 'delivered' || str_starts_with($s, 'delivered') => ShipmentStatus::Delivered,
            str_contains($s, 'out for delivery') => ShipmentStatus::OutForDelivery,
            in_array($s, ['unbooked', 'booked', ''], true) => ShipmentStatus::Booked,
            default => ShipmentStatus::InTransit,
        };
    }

    /** The airway bill is a PDF behind the token header, so we fetch it and serve it. */
    public function label(CourierConnection $connection, Shipment $shipment): LabelResult
    {
        try {
            $response = $this->request($connection)->accept('application/pdf, application/json')
                ->get(self::BASE.'v1/get-invoice', ['trackingNumbers' => $shipment->tracking_number]);
        } catch (ConnectionException) {
            throw new CourierException('Couldn\'t reach PostEx. Try again in a minute.');
        }

        $body = $response->body();

        if ($response->successful() && str_starts_with($body, '%PDF')) {
            return new LabelResult(pdf: $body);
        }

        // Some accounts get the PDF back as base64 inside JSON.
        $json = $response->json();
        $encoded = is_array($json) ? ($json['dist'] ?? $json['data'] ?? null) : null;
        $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;

        if ($response->successful() && $decoded !== false && str_starts_with($decoded, '%PDF')) {
            return new LabelResult(pdf: $decoded);
        }

        throw new CourierException('PostEx didn\'t send the airway bill. Print it from your PostEx dashboard.');
    }

    public function cancel(CourierConnection $connection, Shipment $shipment): void
    {
        try {
            $this->json($this->request($connection)->put(self::BASE.'v1/cancel-order', ['trackingNumber' => $shipment->tracking_number]));
        } catch (ConnectionException) {
            throw new CourierException('Couldn\'t reach PostEx. Try again in a minute.');
        }
    }
}
