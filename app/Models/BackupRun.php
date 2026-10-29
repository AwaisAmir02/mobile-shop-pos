<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BackupRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status',
        'trigger',
        'triggered_by',
        'dumper',
        'archive_name',
        'size_bytes',
        'local_path',
        'remote_path',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public static function lastSuccessful(): ?self
    {
        return self::where('status', self::STATUS_SUCCESS)->latest('finished_at')->first();
    }

    public static function isStale(): bool
    {
        $last = self::lastSuccessful();

        return ! $last || $last->finished_at->lt(now()->subHours((int) config('backup.stale_after_hours')));
    }

    public function sizeLabel(): string
    {
        $bytes = (int) $this->size_bytes;

        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 0).' KB';
    }
}
