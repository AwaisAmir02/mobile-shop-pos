<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by TableExportService when a requested PDF/Excel export would cover
 * more rows than is safe to render synchronously inside a single web
 * request — an unbounded export on a shop with a lot of history can tie up
 * a PHP-FPM worker for long enough to starve unrelated concurrent requests.
 */
class ExportTooLargeException extends RuntimeException
{
    public static function forRowCount(int $count, int $max): self
    {
        return new self(
            "This export would include {$count} rows, which is more than the ".
            "{$max}-row limit for a single PDF/Excel export. Narrow your filters ".
            '(e.g. a shorter date range) and try again.'
        );
    }
}
