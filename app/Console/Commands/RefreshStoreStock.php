<?php

namespace App\Console\Commands;

use App\Models\StoreConnection;
use App\Support\StoreChanges;
use Illuminate\Console\Command;

/**
 * The nightly full stock push. It heals anything a store missed, and it's what tells
 * a store about batches that expired at midnight, a change no write ever announces.
 */
class RefreshStoreStock extends Command
{
    protected $signature = 'store:refresh-stock';

    protected $description = 'Queue every published product\'s stock for connected online stores';

    public function handle(): int
    {
        $connections = StoreConnection::withoutGlobalScopes()->whereNull('removed_at')->whereNotNull('callback_url')->get();

        foreach ($connections as $connection) {
            StoreChanges::current()->everything($connection);
        }

        $this->info("Queued stock for {$connections->count()} connection(s).");

        return self::SUCCESS;
    }
}
