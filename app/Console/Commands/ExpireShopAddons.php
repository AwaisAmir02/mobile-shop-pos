<?php

namespace App\Console\Commands;

use App\Services\Billing\AddonService;
use Illuminate\Console\Command;

class ExpireShopAddons extends Command
{
    protected $signature = 'billing:expire-addons';

    protected $description = 'Expire add-ons whose period has ended and switch off features they unlocked';

    public function handle(AddonService $addons): int
    {
        $count = $addons->expireDue();

        $this->info("Expired {$count} add-on(s).");

        return self::SUCCESS;
    }
}
