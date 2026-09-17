<?php

namespace App\Exports;

use App\Exports\Concerns\FormatsExportSheet;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;

/**
 * One sheet/table within a MultiTableExport — same formatting rules as
 * TableExport (see FormatsExportSheet), plus a sheet title since a
 * multi-sheet workbook needs one per tab.
 */
class TableExportSheet implements FromArray, WithColumnWidths, WithEvents, WithTitle
{
    use FormatsExportSheet;

    public function __construct(
        protected string $sheetTitle,
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

    public function title(): string
    {
        // Excel sheet titles are capped at 31 characters and can't contain
        // certain characters — keep this defensively short and plain.
        return substr(preg_replace('/[\[\]\*\/\\\\\?:]/', '', $this->sheetTitle), 0, 31) ?: 'Sheet';
    }
}
