<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class MonitorBackups extends Command
{
    protected $signature = 'backup:monitor';

    protected $description = 'Email an alert when no backup has succeeded recently';

    public function handle(BackupManager $backups): int
    {
        $this->line($backups->checkStaleness() ? 'Backups are stale.' : 'Backups are up to date.');

        return self::SUCCESS;
    }
}
