<?php

namespace App\Console\Commands;

use App\Support\StoreDelivery;
use Illuminate\Console\Command;

class PushStoreChanges extends Command
{
    protected $signature = 'store:push';

    protected $description = 'Tell connected online stores what has changed, and retry what failed';

    public function handle(StoreDelivery $delivery): int
    {
        $counts = $delivery->run();

        $this->info("Queued {$counts['events']}, delivered {$counts['delivered']}, failed {$counts['failed']}.");

        return self::SUCCESS;
    }
}
