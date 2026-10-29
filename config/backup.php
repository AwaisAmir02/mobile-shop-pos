<?php

return [
    'archive_password' => env('BACKUP_ARCHIVE_PASSWORD'),

    'dumper' => env('BACKUP_DUMPER', 'auto'),
    'mysqldump_path' => env('BACKUP_MYSQLDUMP_PATH'),

    'local_disk' => 'backups',
    'local_keep' => 2,

    'remote_disk' => env('BACKUP_REMOTE_DISK', 'r2'),
    'remote_folder' => 'backups',

    'retention' => [
        'daily' => 7,
        'weekly' => 4,
        'monthly' => 6,
    ],

    'include_paths' => [
        'app/public',
        'app/private',
    ],

    'exclude_paths' => [
        'app/private/livewire-tmp',
        'app/private/product-imports',
    ],

    'stale_after_hours' => 26,
    'alert_email' => env('BACKUP_ALERT_EMAIL'),
    'heartbeat_url' => env('BACKUP_HEARTBEAT_URL'),
    'run_now_per_hour' => 1,
];
