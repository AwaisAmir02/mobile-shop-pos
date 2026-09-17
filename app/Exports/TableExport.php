<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsExportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * A single generic Excel export used by every history/report screen with
 * exactly one table on screen, rather than a hand-rolled export class per
 * screen. Each caller supplies its own headers/rows/column types; see
 * FormatsExportSheet for the actual formatting/typing logic (shared with
 * MultiTableExport, used by screens with more than one table to export).
 *
 * @param  array<int, string>  $headers
 * @param  array<int, array<int, mixed>>  $rows  Each inner array is already ordered to match $headers, with
 *                                               date columns pre-converted via self::excelDate().
 * @param  array<int, string>  $columnTypes  Same order as $headers: 'string'|'integer'|'currency'|'date'.
 */
class TableExport implements FromArray, WithColumnWidths, WithEvents
{
    use FormatsExportSheet;

    public function __construct(
        protected string $title,
        protected string $shopName,
        protected string $filtersSummary,
        protected array $headers,
        protected array $rows,
        protected array $columnTypes,
    ) {}

    public function array(): array
    {
        return [...$this->metaAndHeaderRows(), ...$this->rows];
    }

    public function columnWidths(): array
    {
        return $this->sheetColumnWidths();
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => fn (AfterSheet $event) => $this->styleSheetOnAfterSheet($event),
        ];
    }
}
