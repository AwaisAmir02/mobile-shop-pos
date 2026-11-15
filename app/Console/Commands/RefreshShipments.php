<?php

namespace App\Console\Commands;

use App\Actions\Shipments;
use Illuminate\Console\Command;

/**
 * Asks each courier where every open parcel is. Neither Leopards nor PostEx
 * offers a status webhook a shop can switch on itself, so this polls,
 * oldest-checked first, a batch at a time.
 */
class RefreshShipments extends Command
{
    protected $signature = 'couriers:refresh {--limit=200}';

    protected $description = 'Update the status of open courier parcels from each courier';

    public function handle(): int
    {
        $result = Shipments::refreshOpen((int) $this->option('limit'));
        $this->info("Updated {$result['updated']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
