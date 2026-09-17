<?php

namespace App\Services;

use App\Exports\MultiTableExport;
use App\Exports\TableExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The one shared export mechanism behind every "Export" button in the app
 * (Sales History, Stock In History, Udhaar, Party Ledger, and any screen
 * added later) — each screen's own Livewire component is responsible only
 * for building its own headers/rows/column-types/filter summary from
 * whatever it currently has on screen; this service turns that into a
 * correctly formatted PDF or Excel file. Nothing screen-specific lives
 * here, so there is exactly one place that knows how to lay out a table
 * export, not four hand-rolled copies of the same concern.
 */
class TableExportService
{
    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows  Already display-formatted strings — a PDF is purely visual.
     */
    public function toPdf(
        string $title,
        string $shopName,
        string $filtersSummary,
        array $headers,
        array $rows,
        string $orientation = 'landscape',
    ): StreamedResponse {
        $pdf = Pdf::loadView('pdf.table-export', [
            'title' => $title,
            'shopName' => $shopName,
            'filtersSummary' => $filtersSummary,
            'generatedAt' => now(),
            'headers' => $headers,
            'rows' => $rows,
        ])->setPaper('a4', $orientation);

        $filename = $this->filename($title, 'pdf');

        return response()->streamDownload(fn () => print ($pdf->output()), $filename);
    }

    /**
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows  Real typed values — numbers as numbers, dates via
     *                                               TableExport::excelDate(), never pre-formatted strings.
     * @param  array<int, string>  $columnTypes  Same order as $headers: 'string'|'integer'|'currency'|'date'.
     */
    public function toExcel(
        string $title,
        string $shopName,
        string $filtersSummary,
        array $headers,
        array $rows,
        array $columnTypes,
    ): BinaryFileResponse {
        $filename = $this->filename($title, 'xlsx');

        return Excel::download(
            new TableExport($title, $shopName, $filtersSummary, $headers, $rows, $columnTypes),
            $filename
        );
    }

    /**
     * For a screen with more than one logical table (e.g. the per-customer
     * Udhaar page: Ledger + Other Amounts Owed) — one PDF with a titled
     * section per table.
     *
     * @param  array<int, array{title: string, headers: array<int, string>, rows: array<int, array<int, string>>}>  $sections
     */
    public function toPdfSections(
        string $title,
        string $shopName,
        string $filtersSummary,
        array $sections,
        string $orientation = 'landscape',
    ): StreamedResponse {
        $pdf = Pdf::loadView('pdf.multi-table-export', [
            'title' => $title,
            'shopName' => $shopName,
            'filtersSummary' => $filtersSummary,
            'generatedAt' => now(),
            'sections' => $sections,
        ])->setPaper('a4', $orientation);

        $filename = $this->filename($title, 'pdf');

        return response()->streamDownload(fn () => print ($pdf->output()), $filename);
    }

    /**
     * @param  array<int, array{title: string, headers: array<int, string>, rows: array<int, array<int, mixed>>, columnTypes: array<int, string>}>  $sections
     */
    public function toExcelSections(
        string $title,
        string $shopName,
        string $filtersSummary,
        array $sections,
    ): BinaryFileResponse {
        $filename = $this->filename($title, 'xlsx');

        return Excel::download(
            new MultiTableExport($title, $shopName, $filtersSummary, $sections),
            $filename
        );
    }

    /**
     * Escapes a value that would otherwise be interpreted by Excel/Sheets
     * as a formula when the cell is opened (a leading =, +, -, or @) by
     * prefixing it with a single quote, which forces text interpretation
     * without changing what's visibly displayed.
     */
    public static function sanitizeCell(?string $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    protected function filename(string $title, string $extension): string
    {
        return Str::slug($title).'-'.now()->format('Y-m-d-His').'.'.$extension;
    }
}
