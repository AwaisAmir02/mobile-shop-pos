<?php

namespace App\Services\Backup;

use Ifsnop\Mysqldump\Mysqldump;
use Spatie\DbDumper\Databases\MySql;
use Symfony\Component\Process\Process;

class DatabaseDumper
{
    public const MYSQLDUMP = 'mysqldump';

    public const PHP = 'php';

    public function mysqldumpAvailable(): bool
    {
        if (! function_exists('proc_open')) {
            return false;
        }

        try {
            $binary = rtrim((string) config('backup.mysqldump_path'), '/\\');
            $process = new Process([$binary !== '' ? $binary.DIRECTORY_SEPARATOR.'mysqldump' : 'mysqldump', '--version']);
            $process->setTimeout(10);
            $process->run();

            return $process->isSuccessful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function method(): string
    {
        return match (config('backup.dumper')) {
            self::MYSQLDUMP => self::MYSQLDUMP,
            self::PHP => self::PHP,
            default => $this->mysqldumpAvailable() ? self::MYSQLDUMP : self::PHP,
        };
    }

    public function dumpTo(string $path): string
    {
        $connection = config('database.connections.'.config('database.default'));
        $method = $this->method();

        if ($method === self::MYSQLDUMP) {
            $dumper = MySql::create()
                ->setHost($connection['host'])
                ->setPort((int) $connection['port'])
                ->setDbName($connection['database'])
                ->setUserName($connection['username'])
                ->setPassword((string) $connection['password'])
                ->useSingleTransaction()
                ->skipLockTables()
                ->setDefaultCharacterSet('utf8mb4');

            if (filled(config('backup.mysqldump_path'))) {
                $dumper->setDumpBinaryPath(config('backup.mysqldump_path'));
            }

            $dumper->dumpToFile($path);

            return $method;
        }

        $dump = new Mysqldump(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $connection['host'], $connection['port'], $connection['database']),
            $connection['username'],
            (string) $connection['password'],
            [
                'single-transaction' => true,
                'lock-tables' => false,
                'add-locks' => false,
                'default-character-set' => Mysqldump::UTF8MB4,
                'add-drop-table' => true,
            ],
        );
        $dump->start($path);

        return $method;
    }
}
