<?php

namespace App\Console\Commands;

use App\Actions\BiometricDevices;
use App\Models\BiometricDevice;
use Illuminate\Console\Command;

class SyncMemberDevices extends Command
{
    protected $signature = 'members:sync-devices';

    protected $description = 'Take members whose membership ended off fingerprint devices set to remove them, and put renewed members back';

    public function handle(): int
    {
        $removed = 0;
        $restored = 0;

        BiometricDevice::withoutGlobalScopes()->where('is_active', true)->where('remove_expired', true)->each(function (BiometricDevice $device) use (&$removed, &$restored) {
            $result = BiometricDevices::syncStanding($device);
            $removed += $result['removed'];
            $restored += $result['restored'];
        });

        $this->info("Queued {$removed} removal(s) and {$restored} restore(s).");

        return self::SUCCESS;
    }
}
