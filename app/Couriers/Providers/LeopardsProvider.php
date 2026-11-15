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
 * Leopards Courier (LCS) merchant API. The shop's API key and password come
 * from its own Leopards merchant portal (API Management). Leopards also runs
 * a staging server, so a shop can try a booking before going live.
 *
 * Requests are form-encoded with api_key and api_password on every call;
 * responses are JSON with status 1 (ok) or 0 plus "error". Leopards' push
 * API needs a callback URL registered by Leopards, so status is polled.
 */
class LeopardsProvider implements CourierProvider
{
    public const LIVE = 'https://merchantapi.leopardscourier.com/api/';

    public const STAGING = 'https://merchantapistaging.leopardscourier.com/api/';

    public function credentialFields(): array
    {
        return [
            ['key' => 'api_key', 'label' => 'API Key', 'help' => 'From your Leopards merchant portal, under API Management.', 'secret' => false],
            ['key' => 'api_password', 'label' => 'API Password', 'help' => 'The API password you set in the same place.', 'secret' => true],
            ['key' => 'environment', 'label' => 'Account', 'help' => 'Use Staging only with a Leopards test account.', 'secret' => false, 'options' => ['live' => 'Live', 'staging' => 'Staging (test)']],
        ];
    }

    public function credentialHelp(): string
    {
        return 'You need a Leopards COD merchant account. Log in to the Leopards merchant portal, open API Management, copy the API Key and set an API Password. Parcels are booked from the pickup address on that account.';
    }

    protected function base(CourierConnection $connection): string
    {
        return $connection->credential('environment') === 'staging' ? self::STAGING : self::LIVE;
    }

    /** @return array<string, string> */
    protected function auth(CourierConnection $connection): array
    {
        return ['api_key' => (string) $connection->credential('api_key'), 'api_password' => (string) $connection->credential('api_password')];
    }

    /** @return array<string, mixed> */
    protected function call(CourierConnection $connection, string $method, string $path, array $data = []): array
    {
        try {
            $request = Http::timeout(25)->acceptJson();
            $payload = $this->auth($connection) + $data;

            /** @var Response $response */
            $response = $method === 'GET'
                ? $request->get($this->base($connection).$path, $payload)
                : $request->asForm()->post($this->base($connection).$path, $payload);
        } catch (ConnectionException) {
            throw new CourierException('Couldn\'t reach Leopards. Try again in a minute.');
        }

        $json = $response->json();

        if (! $response->successful() || ! is_array($json)) {
            throw new CourierException('Leopards didn\'t accept the request (HTTP '.$response->status().').');
        }

        if ((int) ($json['status'] ?? 0) !== 1) {
            $error = $json['error'] ?? 'unknown error';

            throw new CourierException('Leopards said: '.(is_array($error) ? implode(' ', array_map(fn ($e) => is_array($e) ? implode(' ', $e) : (string) $e, $error)) : (string) $error));
        }

        return $json;
    }

    public function verify(CourierConnection $connection): array
    {
        $cities = $this->fetchCities($connection);

        if ($cities === []) {
            throw new CourierException('Leopards accepted the key but returned no cities. Check the account with Leopards.');
        }

        return [];
    }

    /** @return array<string, string> id => name */
    protected function fetchCities(CourierConnection $connection): array
    {
        $json = $this->call($connection, 'GET', 'getAllCities/format/json/');

        return collect($json['city_list'] ?? [])
            ->filter(fn ($city) => is_array($city) && isset($city['id'], $city['name']) && strtolower((string) ($city['allow_as_destination'] ?? '1')) !== '0')
            ->mapWithKeys(fn ($city) => [(string) $city['id'] => (string) $city['name']])
            ->sort()
            ->all();
    }

    public function cities(CourierConnection $connection): array
    {
        return Cache::remember('couriers:leopards:cities:'.$this->base($connection), now()->addDay(), fn () => $this->fetchCities($connection));
    }

    public function book(CourierConnection $connection, BookingRequest $request): BookingResult
    {
        $json = $this->call($connection, 'POST', 'bookPacket/format/json/', [
            'booked_packet_weight' => max(1, $request->weightGrams),
            'booked_packet_no_piece' => max(1, $request->pieces),
            'booked_packet_collect_amount' => (int) round($request->codAmount),
            'booked_packet_order_id' => $request->reference,
            // "self" = the pickup city, name, phone and address on the shop's Leopards account.
            'origin_city' => 'self',
            'destination_city' => $request->city,
            'shipment_name_eng' => 'self',
            'shipment_email' => 'self',
            'shipment_phone' => 'self',
            'shipment_address' => 'self',
            'consignment_name_eng' => $request->consigneeName,
            'consignment_email' => $request->consigneeEmail ?? '',
            'consignment_phone' => $request->consigneePhone,
            'consignment_address' => $request->consigneeAddress,
            'special_instructions' => trim($request->description.($request->instructions !== '' ? ' — '.$request->instructions : '')) ?: 'n/a',
            'shipment_type' => 'overnight',
        ]);

        $tracking = trim((string) ($json['track_number'] ?? ''));

        if ($tracking === '') {
            throw new CourierException('Leopards booked the parcel but sent back no tracking number. Check your Leopards portal before booking again.');
        }

        return new BookingResult($tracking, ($json['slip_link'] ?? null) ?: null, $json);
    }

    public function track(CourierConnection $connection, Shipment $shipment): TrackingResult
    {
        $json = $this->call($connection, 'GET', 'trackBookedPacket/format/json/', ['track_numbers' => $shipment->tracking_number]);
        $packet = $json['packet_list'][0] ?? null;

        if (! is_array($packet)) {
            throw new CourierException('Leopards has no tracking for '.$shipment->tracking_number.' yet.');
        }

        $events = collect($packet['Tracking Detail'] ?? $packet['tracking_detail'] ?? [])
            ->filter(fn ($row) => is_array($row))
            ->map(fn ($row) => [
                'at' => $row['Activity_datetime'] ?? $row['activity_datetime'] ?? null,
                'status' => (string) ($row['Status'] ?? $row['status'] ?? ''),
                'note' => ($row['Reason'] ?? $row['Reciever_Name'] ?? null) ?: null,
            ])
            ->values()
            ->all();

        $courierStatus = (string) ($packet['booked_packet_status'] ?? (end($events)['status'] ?? 'Booked'));

        return new TrackingResult(self::mapStatus($courierStatus), $courierStatus, $events, $packet);
    }

    /** Leopards' wording onto ours. Returns are checked before deliveries ("Return Delivered"). */
    public static function mapStatus(string $status): ShipmentStatus
    {
        $s = strtolower($status);

        return match (true) {
            str_contains($s, 'cancel') => ShipmentStatus::Cancelled,
            str_contains($s, 'returned to shipper'), str_contains($s, 'return delivered'), $s === 'returned' => ShipmentStatus::Returned,
            str_contains($s, 'return') => ShipmentStatus::Returning,
            str_contains($s, 'delivered') && ! str_contains($s, 'undelivered') && ! str_contains($s, 'not delivered') => ShipmentStatus::Delivered,
            str_contains($s, 'assigned to courier'), str_contains($s, 'out for delivery') => ShipmentStatus::OutForDelivery,
            str_contains($s, 'pickup request'), str_contains($s, 'booked'), $s === 'pending', $s === '' => ShipmentStatus::Booked,
            default => ShipmentStatus::InTransit,
        };
    }

    public function label(CourierConnection $connection, Shipment $shipment): LabelResult
    {
        if (! $shipment->label_url) {
            throw new CourierException('Leopards didn\'t send a label link for this parcel. Print it from your Leopards portal.');
        }

        return new LabelResult(url: $shipment->label_url);
    }

    public function cancel(CourierConnection $connection, Shipment $shipment): void
    {
        $this->call($connection, 'POST', 'cancelBookedPackets/format/json/', ['cn_numbers' => $shipment->tracking_number]);
    }
}
