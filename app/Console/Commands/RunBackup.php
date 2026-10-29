<?php

namespace App\Console\Commands;

use App\Models\BackupRun;
use App\Services\Backup\BackupManager;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Back up the database and uploaded files to an encrypted archive, on the server and off-site';

    public function handle(BackupManager $backups): int
    {
        try {
            $run = $backups->run('schedule');
        } catch (\RuntimeException $e) {
            $this->warn($e->getMessage());

            return self::SUCCESS;
        }

        if ($run->status !== BackupRun::STATUS_SUCCESS) {
            $this->error('Backup failed: '.$run->error);

            return self::FAILURE;
        }

        $this->info("Backup {$run->archive_name} ({$run->sizeLabel()}) stored on the server and off-site.");

        return self::SUCCESS;
    }
}
