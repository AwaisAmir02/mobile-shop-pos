<?php

namespace App\Console\Commands;

use App\Couriers\BookingRequest;
use App\Couriers\CourierException;
use App\Enums\CourierKey;
use App\Models\CourierConnection;
use App\Models\Shipment;
use Illuminate\Console\Command;

/**
 * A real end-to-end check against a courier, outside any shop: connect,
 * list cities, book one parcel, fetch its label and tracking, then cancel it.
 * Nothing is saved. Use a courier TEST account (Leopards staging); on a live
 * account the booking is real until the cancel goes through.
 *
 *   php artisan couriers:try leopards --key=... --password=... --staging --city="Lahore"
 *   php artisan couriers:try postex --token=... --pickup=... --city="Lahore"
 */
class TryCourierBooking extends Command
{
    protected $signature = 'couriers:try {courier} {--key=} {--password=} {--token=} {--pickup=} {--staging} {--city=Lahore} {--keep : Don\'t cancel the test parcel}';

    protected $description = 'Book, label, track and cancel one test parcel with a courier account';

    public function handle(): int
    {
        $courier = CourierKey::tryFrom((string) $this->argument('courier'));

        if (! $courier?->isAvailable()) {
            $this->error('Choose one of: '.implode(', ', array_map(fn ($c) => $c->value, CourierKey::available())));

            return self::FAILURE;
        }

        $connection = new CourierConnection(['courier' => $courier, 'settings' => array_filter(['pickup_address_code' => $this->option('pickup')])]);
        $connection->credentials = array_filter([
            'api_key' => $this->option('key'),
            'api_password' => $this->option('password'),
            'environment' => $this->option('staging') ? 'staging' : 'live',
            'token' => $this->option('token'),
        ]);
        $provider = $courier->provider();

        try {
            $this->line('1. Checking the account…');
            $settings = $provider->verify($connection);
            if (! empty($settings['pickup_addresses'])) {
                $this->table(['Code', 'Pickup address'], array_map(fn ($a) => [$a['code'], $a['label']], $settings['pickup_addresses']));
                $connection->settings = ['pickup_address_code' => $this->option('pickup') ?: $settings['pickup_addresses'][0]['code']];
            }

            $this->line('2. Finding the city…');
            $cities = $provider->cities($connection);
            $cityValue = collect($cities)->search(fn ($name) => strcasecmp($name, (string) $this->option('city')) === 0);
            if ($cityValue === false) {
                $this->error('No city called "'.$this->option('city').'" in '.count($cities).' cities.');

                return self::FAILURE;
            }

            $this->line('3. Booking a test parcel…');
            $result = $provider->book($connection, new BookingRequest(
                reference: 'TEST-'.now()->format('YmdHis'),
                consigneeName: 'Test Consignee',
                consigneePhone: '03001234567',
                consigneeAddress: 'House 1, Street 1, Test Area (TEST BOOKING, PLEASE IGNORE)',
                city: (string) $cityValue,
                cityName: $cities[$cityValue],
                codAmount: 100,
                weightGrams: 500,
                pieces: 1,
                description: 'Test parcel',
                instructions: 'Test booking from HisabOKitab, will be cancelled',
            ));
            $this->info('   Tracking number: '.$result->trackingNumber);

            $shipment = new Shipment(['tracking_number' => $result->trackingNumber, 'label_url' => $result->labelUrl]);

            $this->line('4. Getting the label…');
            $label = $provider->label($connection, $shipment);
            if ($label->pdf !== null) {
                $path = storage_path('app/courier-test-label-'.$courier->value.'.pdf');
                file_put_contents($path, $label->pdf);
                $this->info('   Label PDF saved: '.$path.' ('.strlen($label->pdf).' bytes)');
            } else {
                $this->info('   Label link: '.$label->url);
            }

            $this->line('5. Tracking…');
            $tracking = $provider->track($connection, $shipment);
            $this->info('   '.$tracking->courierStatus.' → '.$tracking->status->label());

            if (! $this->option('keep')) {
                $this->line('6. Cancelling the test parcel…');
                $provider->cancel($connection, $shipment);
                $this->info('   Cancelled.');
            }
        } catch (CourierException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Done: the '.$courier->label().' account books, labels and tracks.');

        return self::SUCCESS;
    }
}
