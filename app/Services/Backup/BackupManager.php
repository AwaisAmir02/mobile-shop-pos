<?php

namespace App\Services\Backup;

use App\Mail\BackupFailedMail;
use App\Models\BackupRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use ZipArchive;

class BackupManager
{
    public const LOCK = 'backup-running';

    public function __construct(protected DatabaseDumper $dumper) {}

    public function remoteConfigured(): bool
    {
        $disk = config('backup.remote_disk');
        $settings = config("filesystems.disks.{$disk}");

        if (! $settings) {
            return false;
        }

        if (($settings['driver'] ?? null) !== 's3') {
            return true;
        }

        return filled($settings['key'] ?? null) && filled($settings['secret'] ?? null) && filled($settings['bucket'] ?? null) && filled($settings['endpoint'] ?? null);
    }

    public function run(string $trigger, ?int $userId = null): BackupRun
    {
        $lock = Cache::lock(self::LOCK, 3600);

        if (! $lock->get()) {
            throw new RuntimeException('A backup is already running.');
        }

        $run = BackupRun::create([
            'status' => BackupRun::STATUS_RUNNING,
            'trigger' => $trigger,
            'triggered_by' => $userId,
            'started_at' => now(),
        ]);

        $workDir = storage_path('app/backup-tmp/'.$run->id);

        try {
            $password = (string) config('backup.archive_password');

            if ($password === '') {
                throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD is not set, so the archive cannot be encrypted. Set it in .env.');
            }

            File::ensureDirectoryExists($workDir);

            $dumpPath = $workDir.'/database.sql';
            $method = $this->dumper->dumpTo($dumpPath);

            $name = 'hisabokitab-'.now()->format('Y-m-d-His').'.zip';
            $zipPath = $workDir.'/'.$name;
            $this->zip($zipPath, $dumpPath, $password);

            $local = Storage::disk(config('backup.local_disk'));
            $stream = fopen($zipPath, 'r');
            $local->writeStream($name, $stream);
            fclose($stream);
            $this->pruneLocal();

            $run->update(['dumper' => $method, 'archive_name' => $name, 'size_bytes' => filesize($zipPath), 'local_path' => $name]);

            if (! $this->remoteConfigured()) {
                throw new RuntimeException('Off-site storage (Cloudflare R2) is not configured — a local copy was kept, but the backup is not safe off the server.');
            }

            $remotePath = trim(config('backup.remote_folder'), '/').'/'.$name;
            $stream = fopen($zipPath, 'r');
            Storage::disk(config('backup.remote_disk'))->writeStream($remotePath, $stream);
            fclose($stream);
            $this->pruneRemote();

            $run->update(['status' => BackupRun::STATUS_SUCCESS, 'remote_path' => $remotePath, 'finished_at' => now()]);
            $this->heartbeat();
        } catch (\Throwable $e) {
            $run->update(['status' => BackupRun::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()]);
            $this->alert($run->fresh());
            report($e);
        } finally {
            File::deleteDirectory($workDir);
            $lock->release();
        }

        return $run->fresh();
    }

    protected function zip(string $zipPath, string $dumpPath, string $password): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup archive.');
        }

        $zip->setPassword($password);
        $this->addEncrypted($zip, $dumpPath, 'database.sql');

        $excluded = array_map(fn ($path) => str_replace('\\', '/', storage_path($path)), config('backup.exclude_paths'));

        foreach (config('backup.include_paths') as $relative) {
            $root = storage_path($relative);

            if (! is_dir($root)) {
                continue;
            }

            foreach (Finder::create()->files()->in($root)->ignoreDotFiles(false) as $file) {
                $real = str_replace('\\', '/', $file->getRealPath());

                if (collect($excluded)->contains(fn ($prefix) => str_starts_with($real, rtrim($prefix, '/').'/'))) {
                    continue;
                }

                $this->addEncrypted($zip, $file->getRealPath(), 'files/'.$relative.'/'.str_replace('\\', '/', $file->getRelativePathname()));
            }
        }

        if (! $zip->close()) {
            throw new RuntimeException('Could not finish writing the backup archive.');
        }
    }

    protected function addEncrypted(ZipArchive $zip, string $path, string $name): void
    {
        $zip->addFile($path, $name);

        if (! $zip->setEncryptionName($name, ZipArchive::EM_AES_256)) {
            throw new RuntimeException('Could not encrypt '.$name.' in the backup archive.');
        }
    }

    protected function pruneLocal(): void
    {
        $disk = Storage::disk(config('backup.local_disk'));
        $files = collect($disk->files())->filter(fn ($file) => str_ends_with($file, '.zip'))->sortDesc()->values();

        $files->slice((int) config('backup.local_keep'))->each(fn ($file) => $disk->delete($file));
    }

    protected function pruneRemote(): void
    {
        $disk = Storage::disk(config('backup.remote_disk'));
        $folder = trim(config('backup.remote_folder'), '/');

        $archives = collect($disk->files($folder))
            ->map(function (string $path) {
                if (! preg_match('/hisabokitab-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/', $path, $m)) {
                    return null;
                }

                return ['path' => $path, 'at' => Carbon::createFromFormat('Y-m-d-His', $m[1])];
            })
            ->filter()
            ->values()
            ->all();

        $retention = config('backup.retention');
        $keep = BackupRetention::keep($archives, $retention['daily'], $retention['weekly'], $retention['monthly']);

        foreach ($archives as $archive) {
            if (! in_array($archive['path'], $keep, true)) {
                $disk->delete($archive['path']);
            }
        }
    }

    protected function heartbeat(): void
    {
        if (blank(config('backup.heartbeat_url'))) {
            return;
        }

        try {
            Http::timeout(10)->get(config('backup.heartbeat_url'));
        } catch (\Throwable) {
        }
    }

    public function alert(BackupRun $run, string $reason = 'failed'): void
    {
        if (blank(config('backup.alert_email'))) {
            return;
        }

        try {
            Mail::to(config('backup.alert_email'))->send(new BackupFailedMail($run, $reason));
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function checkStaleness(): bool
    {
        if (! BackupRun::isStale()) {
            return false;
        }

        if (Cache::add('backup-stale-alert', true, now()->addHours(24))) {
            $this->alert(BackupRun::latest('id')->first() ?? new BackupRun(['status' => 'none', 'started_at' => now()]), 'stale');
        }

        return true;
    }
}
