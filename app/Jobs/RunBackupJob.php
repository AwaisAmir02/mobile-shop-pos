<?php

namespace App\Jobs;

use App\Services\Backup\BackupManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(public int $userId) {}

    public function handle(BackupManager $backups): void
    {
        try {
            $backups->run('manual', $this->userId);
        } catch (\RuntimeException) {
        }
    }
}
