<?php

namespace App\Services\Backup;

use Carbon\CarbonInterface;

class BackupRetention
{
    /**
     * @param  array<int, array{path: string, at: CarbonInterface}>  $archives
     * @return array<int, string>
     */
    public static function keep(array $archives, int $daily, int $weekly, int $monthly): array
    {
        usort($archives, fn ($a, $b) => $b['at'] <=> $a['at']);

        $keep = [];
        $buckets = [
            ['format' => 'Y-m-d', 'limit' => $daily, 'seen' => []],
            ['format' => 'o-W', 'limit' => $weekly, 'seen' => []],
            ['format' => 'Y-m', 'limit' => $monthly, 'seen' => []],
        ];

        foreach ($buckets as $bucket) {
            foreach ($archives as $archive) {
                $key = $archive['at']->format($bucket['format']);

                if (isset($bucket['seen'][$key])) {
                    continue;
                }

                if (count($bucket['seen']) >= $bucket['limit']) {
                    break;
                }

                $bucket['seen'][$key] = true;
                $keep[$archive['path']] = true;
            }
        }

        return array_keys($keep);
    }
}
