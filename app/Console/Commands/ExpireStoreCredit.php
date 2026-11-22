<?php

namespace App\Console\Commands;

use App\Actions\StoreCredit;
use Illuminate\Console\Command;

class ExpireStoreCredit extends Command
{
    protected $signature = 'credits:expire';

    protected $description = 'Write off store credit left after its expiry date (only shops that set one)';

    public function handle(): int
    {
        $count = StoreCredit::expireDue();

        $this->info("Wrote off store credit on {$count} return(s).");

        return self::SUCCESS;
    }
}
