<?php

namespace App\Livewire\Concerns;

use App\Exceptions\ExportTooLargeException;
use Closure;

/**
 * Every screen's exportPdf/exportExcel action routes its actual work
 * through this so an oversized export (see TableExportService::MAX_ROWS)
 * degrades to a toast instead of a long-running request or a fatal error.
 * Requires the Toasts trait on the same component.
 */
trait GuardsExportSize
{
    protected function guardExportSize(Closure $export): mixed
    {
        try {
            return $export();
        } catch (ExportTooLargeException $e) {
            $this->toastError($e->getMessage());

            return null;
        }
    }
}
