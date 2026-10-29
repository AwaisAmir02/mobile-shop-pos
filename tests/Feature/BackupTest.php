<?php

namespace Tests\Feature;

use App\Jobs\RunBackupJob;
use App\Mail\BackupFailedMail;
use App\Models\BackupRun;
use App\Models\Shop;
use App\Models\User;
use App\Services\Backup\BackupManager;
use App\Services\Backup\BackupRetention;
use App\Services\Backup\DatabaseDumper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $probe;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'backup.archive_password' => 'test-archive-secret',
            'backup.dumper' => DatabaseDumper::PHP,
            'backup.alert_email' => 'ops@hisabokitab.test',
            'filesystems.disks.r2.key' => 'k', 'filesystems.disks.r2.secret' => 's',
            'filesystems.disks.r2.bucket' => 'b', 'filesystems.disks.r2.endpoint' => 'https://r2.test',
        ]);
        Storage::fake('backups');
        Storage::fake('r2');
        Cache::lock(BackupManager::LOCK)->forceRelease();
        RateLimiter::clear('backup-run-now');

        $this->probe = storage_path('app/public/backup-test-probe.txt');
        file_put_contents($this->probe, 'probe-contents');
    }

    protected function tearDown(): void
    {
        @unlink($this->probe);
        parent::tearDown();
    }

    protected function admin(): User
    {
        return User::factory()->create(['is_super_admin' => true, 'shop_id' => null]);
    }

    protected function openArchive(string $disk, string $path): ZipArchive
    {
        $local = tempnam(sys_get_temp_dir(), 'bk');
        file_put_contents($local, Storage::disk($disk)->get($path));
        $zip = new ZipArchive;
        $zip->open($local);

        return $zip;
    }

    public function test_retention_keeps_7_daily_4_weekly_and_6_monthly(): void
    {
        $archives = [];
        $start = Carbon::parse('2026-11-02 03:30:00');

        for ($day = 0; $day < 400; $day++) {
            $archives[] = ['path' => "d{$day}", 'at' => $start->copy()->subDays($day)];
        }

        $keep = BackupRetention::keep($archives, 7, 4, 6);

        $daily = ['d0', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6'];
        $weekly = ['d0', 'd1', 'd8', 'd15'];
        $monthly = ['d0', 'd2', 'd33', 'd63', 'd94', 'd125'];

        $this->assertEqualsCanonicalizing(array_values(array_unique([...$daily, ...$weekly, ...$monthly])), $keep);
    }

    public function test_a_backup_writes_an_encrypted_archive_on_the_server_and_off_site(): void
    {
        $run = app(BackupManager::class)->run('schedule');

        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status, (string) $run->error);
        $this->assertSame(DatabaseDumper::PHP, $run->dumper);
        $this->assertGreaterThan(0, $run->size_bytes);
        Storage::disk('backups')->assertExists($run->local_path);
        Storage::disk('r2')->assertExists($run->remote_path);
        $this->assertStringStartsWith('backups/hisabokitab-', $run->remote_path);

        $zip = $this->openArchive('r2', $run->remote_path);
        $this->assertFalse($zip->getFromName('database.sql'));

        $zip->setPassword('test-archive-secret');
        $sql = $zip->getFromName('database.sql');
        $this->assertStringContainsString('CREATE TABLE', $sql);
        $this->assertStringContainsString('`backup_runs`', $sql);
        $this->assertSame('probe-contents', $zip->getFromName('files/app/public/backup-test-probe.txt'));
        $zip->close();

        $this->assertDirectoryDoesNotExist(storage_path('app/backup-tmp/'.$run->id));
        $this->assertFalse(Cache::has(BackupManager::LOCK));
    }

    public function test_only_the_last_two_archives_stay_on_the_server(): void
    {
        foreach (range(1, 3) as $i) {
            $this->travel(2)->seconds();
            app(BackupManager::class)->run('schedule');
        }

        $this->assertCount(2, Storage::disk('backups')->files());
        $this->assertCount(1, Storage::disk('r2')->files('backups'));
    }

    public function test_old_off_site_archives_are_pruned_by_the_retention_rules(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 03:30:00'));

        foreach (range(1, 60) as $day) {
            Storage::disk('r2')->put('backups/hisabokitab-'.now()->subDays($day)->format('Y-m-d-His').'.zip', 'x');
        }
        Storage::disk('r2')->put('backups/unrelated.txt', 'keep me');

        app(BackupManager::class)->run('schedule');

        $left = Storage::disk('r2')->files('backups');
        $this->assertContains('backups/unrelated.txt', $left);
        $this->assertLessThanOrEqual(1 + 7 + 4 + 6, count($left));
        $this->assertContains('backups/hisabokitab-'.now()->subDays(6)->format('Y-m-d-His').'.zip', $left);
        $this->assertContains('backups/hisabokitab-'.now()->subDays(8)->format('Y-m-d-His').'.zip', $left);
        $this->assertNotContains('backups/hisabokitab-'.now()->subDays(9)->format('Y-m-d-His').'.zip', $left);
    }

    public function test_a_missing_password_or_r2_fails_loudly_and_alerts(): void
    {
        Mail::fake();

        config(['backup.archive_password' => null]);
        $run = app(BackupManager::class)->run('schedule');
        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('BACKUP_ARCHIVE_PASSWORD', $run->error);
        Mail::assertSent(BackupFailedMail::class, fn ($mail) => $mail->hasTo('ops@hisabokitab.test'));
        Storage::disk('backups')->assertDirectoryEmpty('/');

        config(['backup.archive_password' => 'test-archive-secret', 'filesystems.disks.r2.key' => null]);
        $run = app(BackupManager::class)->run('schedule');
        $this->assertSame(BackupRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Cloudflare R2', $run->error);
        Storage::disk('backups')->assertExists($run->local_path);
        Mail::assertSent(BackupFailedMail::class, 2);
    }

    public function test_two_backups_never_run_at_once(): void
    {
        $lock = Cache::lock(BackupManager::LOCK, 60);
        $lock->get();

        $this->expectExceptionMessage('already running');

        try {
            app(BackupManager::class)->run('schedule');
        } finally {
            $lock->release();
            $this->assertSame(0, BackupRun::count());
        }
    }

    public function test_the_monitor_alerts_once_a_day_when_backups_are_stale(): void
    {
        Mail::fake();

        $this->artisan('backup:monitor')->expectsOutput('Backups are stale.')->assertSuccessful();
        $this->artisan('backup:monitor');
        Mail::assertSent(BackupFailedMail::class, 1);

        app(BackupManager::class)->run('schedule');
        Cache::forget('backup-stale-alert');
        $this->artisan('backup:monitor')->expectsOutput('Backups are up to date.');
        Mail::assertSent(BackupFailedMail::class, 1);

        $this->travel(27)->hours();
        $this->artisan('backup:monitor')->expectsOutput('Backups are stale.');
        Mail::assertSent(BackupFailedMail::class, 2);
    }

    public function test_run_now_is_super_admin_only_and_limited_to_once_an_hour(): void
    {
        Queue::fake();
        $shop = Shop::create(['name' => 'Shop']);
        $owner = User::factory()->create(['shop_id' => $shop->id]);

        $this->actingAs($owner)->get(route('admin.backups'))->assertForbidden();
        Livewire::actingAs($owner)->test('admin.backups')->assertForbidden();

        $admin = $this->admin();
        Livewire::actingAs($admin)->test('admin.backups')->call('runNow')->call('runNow');
        Queue::assertPushed(RunBackupJob::class, 1);

        $this->travel(61)->minutes();
        Livewire::actingAs($admin)->test('admin.backups')->call('runNow');
        Queue::assertPushed(RunBackupJob::class, 2);
    }

    public function test_the_page_dashboard_and_download_show_backup_state(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Backups need attention.');
        Livewire::actingAs($admin)->test('admin.backups')->call('runChecks')->assertSee('Encrypted zip (AES-256)')->assertSee('Configured');

        (new RunBackupJob($admin->id))->handle(app(BackupManager::class));
        $run = BackupRun::sole();
        $this->assertSame('manual', $run->trigger);

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertSee('Backups OK.');
        $this->actingAs($admin)->get(route('admin.backups'))->assertSee('Manual · '.$admin->name);
        $this->actingAs($admin)->get(route('admin.backups.download', $run))->assertOk()->assertDownload($run->archive_name);

        $shop = Shop::create(['name' => 'Shop']);
        $this->actingAs(User::factory()->create(['shop_id' => $shop->id]))->get(route('admin.backups.download', $run))->assertForbidden();
    }

    public function test_the_mysqldump_path_works_where_the_program_exists(): void
    {
        $bin = 'D:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin';

        if (! is_file($bin.'\\mysqldump.exe')) {
            $this->markTestSkipped('mysqldump is not installed on this machine.');
        }

        config(['backup.dumper' => 'auto', 'backup.mysqldump_path' => $bin]);
        $this->assertTrue(app(DatabaseDumper::class)->mysqldumpAvailable());

        $run = app(BackupManager::class)->run('schedule');
        $this->assertSame(BackupRun::STATUS_SUCCESS, $run->status, (string) $run->error);
        $this->assertSame(DatabaseDumper::MYSQLDUMP, $run->dumper);

        $zip = $this->openArchive('r2', $run->remote_path);
        $zip->setPassword('test-archive-secret');
        $this->assertStringContainsString('CREATE TABLE', $zip->getFromName('database.sql'));
    }
}
