<?php

use App\Jobs\RunBackupJob;
use App\Livewire\Concerns\Toasts;
use App\Models\BackupRun;
use App\Services\Backup\BackupManager;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Backups')] class extends Component
{
    use Toasts;

    public ?array $checks = null;

    protected function authorizeSuperAdmin(): void
    {
        abort_unless(Auth::user()?->is_super_admin, 403);
    }

    public function mount(): void
    {
        $this->authorizeSuperAdmin();
    }

    public function runChecks(DatabaseDumper $dumper, BackupManager $backups): void
    {
        $this->authorizeSuperAdmin();

        $mysqldump = $dumper->mysqldumpAvailable();

        $this->checks = [
            ['label' => 'PHP can run programs (proc_open)', 'ok' => function_exists('proc_open'), 'detail' => function_exists('proc_open') ? 'Available' : 'Disabled by the host'],
            ['label' => 'mysqldump program', 'ok' => $mysqldump, 'detail' => $mysqldump ? 'Found — backups use mysqldump' : 'Not available — backups use the built-in PHP dumper (same result, a little slower)', 'optional' => true],
            ['label' => 'Database dump method', 'ok' => true, 'detail' => $dumper->method() === DatabaseDumper::MYSQLDUMP ? 'mysqldump (consistent snapshot, no table locks)' : 'PHP dumper (consistent snapshot, no table locks)'],
            ['label' => 'Encrypted zip (AES-256)', 'ok' => class_exists(ZipArchive::class) && method_exists(ZipArchive::class, 'setEncryptionName'), 'detail' => class_exists(ZipArchive::class) ? 'Supported' : 'PHP zip extension missing'],
            ['label' => 'Archive password (BACKUP_ARCHIVE_PASSWORD)', 'ok' => filled(config('backup.archive_password')), 'detail' => filled(config('backup.archive_password')) ? 'Set' : 'Not set — backups will fail until it is'],
            ['label' => 'Off-site storage (Cloudflare R2)', 'ok' => $backups->remoteConfigured(), 'detail' => $backups->remoteConfigured() ? 'Configured' : 'Not configured — set R2_ACCESS_KEY_ID, R2_SECRET_ACCESS_KEY, R2_BUCKET and R2_ENDPOINT'],
            ['label' => 'Failure alert email (BACKUP_ALERT_EMAIL)', 'ok' => filled(config('backup.alert_email')), 'detail' => config('backup.alert_email') ?: 'Not set — failures only show on this page'],
            ['label' => 'Heartbeat URL (optional)', 'ok' => filled(config('backup.heartbeat_url')), 'detail' => filled(config('backup.heartbeat_url')) ? 'Set — an outside monitor is pinged after each backup' : 'Not set — optional, catches a stopped cron', 'optional' => true],
        ];
    }

    public function runNow(): void
    {
        $this->authorizeSuperAdmin();

        $key = 'backup-run-now';

        if (RateLimiter::tooManyAttempts($key, (int) config('backup.run_now_per_hour'))) {
            $this->toastError('A manual backup was started recently. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.');

            return;
        }

        if (Cache::has(BackupManager::LOCK)) {
            $this->toastError('A backup is already running.');

            return;
        }

        RateLimiter::hit($key, 3600);
        RunBackupJob::dispatch(Auth::id());

        $this->toastSuccess('Backup queued — it starts within a minute. Refresh this page to see it.');
    }

    public function with(): array
    {
        $this->authorizeSuperAdmin();

        return [
            'lastSuccess' => BackupRun::lastSuccessful(),
            'stale' => BackupRun::isStale(),
            'runs' => BackupRun::with('triggeredBy')->latest('id')->limit(20)->get(),
            'localFiles' => collect(\Illuminate\Support\Facades\Storage::disk(config('backup.local_disk'))->files())->all(),
        ];
    }
}; ?>

<div>
    <x-slot name="header">
        <h1 class="text-xl font-semibold text-slate-900">Backups</h1>
    </x-slot>

    <div class="space-y-6">
        <x-ui.card>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p @class(['text-lg font-semibold', 'text-red-700' => $stale, 'text-emerald-700' => ! $stale])>
                        {{ $stale ? 'No recent backup' : 'Backups are up to date' }}
                    </p>
                    <p class="text-sm text-slate-500">
                        Last successful backup:
                        {{ $lastSuccess ? $lastSuccess->finished_at->timezone('Asia/Karachi')->format('d M Y, h:i A').' PKT ('.$lastSuccess->finished_at->diffForHumans().', '.$lastSuccess->sizeLabel().')' : 'never' }}
                    </p>
                    <p class="text-xs text-slate-400">Runs every day at 3:30 AM PKT. Encrypted archive kept off-site (7 daily, 4 weekly, 6 monthly) plus the last {{ config('backup.local_keep') }} on this server.</p>
                </div>
                <x-ui.button wire:click="runNow" wire:confirm="Start a backup now? Allowed once per hour.">Run backup now</x-ui.button>
            </div>
        </x-ui.card>

        <x-ui.card title="System check" description="Confirms what this server supports and what still needs configuring.">
            <x-slot name="actions">
                <x-ui.button size="sm" variant="secondary" wire:click="runChecks" wire:loading.attr="disabled" wire:target="runChecks">{{ $checks ? 'Check again' : 'Run check' }}</x-ui.button>
            </x-slot>

            @if ($checks)
                <ul class="divide-y divide-slate-100">
                    @foreach ($checks as $check)
                        <li class="flex items-start justify-between gap-4 py-2.5 text-sm">
                            <span class="font-medium text-slate-800">{{ $check['label'] }}</span>
                            <span @class(['text-right', 'text-emerald-700' => $check['ok'], 'text-amber-700' => ! $check['ok'] && ($check['optional'] ?? false), 'text-red-700' => ! $check['ok'] && ! ($check['optional'] ?? false)])>{{ $check['detail'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="text-sm text-slate-500">Run the check to see whether mysqldump, encryption and off-site storage are ready on this server.</p>
            @endif
        </x-ui.card>

        <x-ui.card title="Recent backups">
            @if ($runs->isEmpty())
                <x-ui.empty-state title="No backups yet" description="The first one runs tonight at 3:30 AM PKT, or start one now." />
            @else
                <x-ui.table :headers="['Started', 'Trigger', 'Method', 'Size', 'Status', '']">
                    @foreach ($runs as $run)
                        <x-ui.table-row wire:key="run-{{ $run->id }}">
                            <x-ui.table-cell>{{ $run->started_at->timezone('Asia/Karachi')->format('d M Y, h:i A') }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $run->trigger === 'manual' ? 'Manual'.($run->triggeredBy ? ' · '.$run->triggeredBy->name : '') : 'Scheduled' }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $run->dumper ?? '—' }}</x-ui.table-cell>
                            <x-ui.table-cell>{{ $run->size_bytes ? $run->sizeLabel() : '—' }}</x-ui.table-cell>
                            <x-ui.table-cell>
                                <x-ui.badge :variant="match ($run->status) { 'success' => 'success', 'failed' => 'danger', default => 'warning' }">{{ ucfirst($run->status) }}</x-ui.badge>
                                @if ($run->error)
                                    <p class="mt-1 max-w-md text-xs text-red-700">{{ $run->error }}</p>
                                @endif
                            </x-ui.table-cell>
                            <x-ui.table-cell align="right">
                                @if ($run->archive_name && (in_array($run->local_path, $localFiles, true) || $run->remote_path))
                                    <a href="{{ route('admin.backups.download', $run) }}" class="text-sm font-semibold text-brand-700 hover:underline">Download</a>
                                @endif
                            </x-ui.table-cell>
                        </x-ui.table-row>
                    @endforeach
                </x-ui.table>
            @endif
        </x-ui.card>

        <x-ui.card title="How to restore" description="Keep these steps — and the archive password and APP_KEY — somewhere safe outside this server.">
            <ol class="list-decimal space-y-2 pl-5 text-sm text-slate-700">
                <li>Download the backup you need from the list above (or from the Cloudflare R2 bucket, folder <code>{{ config('backup.remote_folder') }}/</code>).</li>
                <li>Open the zip with 7-Zip using the archive password (<code>BACKUP_ARCHIVE_PASSWORD</code>). It contains <code>database.sql</code> and a <code>files/</code> folder.</li>
                <li>Database: in cPanel → <em>Backup</em> → “Restore a MySQL Database” (or phpMyAdmin → Import for small files), import <code>database.sql</code> into the database. Take a fresh backup of the current state first if anything there is still usable.</li>
                <li>Files: in cPanel File Manager, upload the contents of <code>files/app/public</code> and <code>files/app/private</code> into <code>storage/app/public</code> and <code>storage/app/private</code>.</li>
                <li>Make sure <code>.env</code> has the <strong>same APP_KEY</strong> as when the backup was taken — saved WhatsApp, Meta and integration tokens are encrypted with it and won’t open otherwise.</li>
                <li>Log in, open a few sales, customers’ udhaar and products to confirm the numbers look right.</li>
            </ol>
        </x-ui.card>
    </div>
</div>
