<?php

namespace App\Exports\Concerns;

use App\Support\Quantity;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * The actual "make this a well-formed, correctly-typed spreadsheet" logic
 * shared by every table export in the app, whether it's rendered as one
 * standalone file (TableExport) or as one sheet inside a multi-sheet
 * export (TableExportSheet, for a screen like the per-customer Udhaar
 * page that has more than one logical table to export). Both classes
 * declare the same $title/$shopName/$filtersSummary/$headers/$rows/
 * $columnTypes properties this trait reads.
 *
 *  - numeric/currency/date columns are written as real typed cells (not
 *    text) with an explicit number format, so Excel can sum/sort them and
 *    dates never render as "####";
 *  - every column gets an explicit minimum width, so nothing garbles;
 *  - the header row is bold and shaded;
 *  - a small metadata block (title, shop, generated-at, active filters)
 *    sits above the table so the file is self-describing out of context.
 */
trait FormatsExportSheet
{
    protected const META_ROW_COUNT = 5; // title, shop, generated-at, filters, spacer

    protected const HEADER_ROW = self::META_ROW_COUNT + 1;

    protected function metaAndHeaderRows(): array
    {
        // The writer drops an empty array row, shifting everything below up; blank strings keep the spacer.
        $spacer = array_fill(0, max(1, count($this->headers)), '');

        return [
            [$this->title],
            [$this->shopName],
            ['Generated: '.now()->format('d M Y, h:i A')],
            [$this->filtersSummary],
            $spacer,
            $this->headers,
        ];
    }

    protected function sheetColumnWidths(): array
    {
        $widths = [];

        foreach ($this->headers as $i => $header) {
            $type = $this->columnTypes[$i] ?? 'string';
            $letter = Coordinate::stringFromColumnIndex($i + 1);

            $widths[$letter] = match ($type) {
                'date' => 18,
                'currency' => 16,
                'integer', 'quantity' => 12,
                default => max(16, strlen((string) $header) + 6),
            };
        }

        return $widths;
    }

    protected function styleSheetOnAfterSheet(AfterSheet $event): void
    {
        $sheet = $event->sheet->getDelegate();
        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($this->headers)));
        $headerRow = self::HEADER_ROW;

        $sheet->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells("A1:{$lastColumn}1");
        $sheet->mergeCells("A2:{$lastColumn}2");
        $sheet->mergeCells("A3:{$lastColumn}3");
        $sheet->mergeCells("A4:{$lastColumn}4");

        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E2E8F0');

        if (empty($this->rows)) {
            return;
        }

        $firstDataRow = $headerRow + 1;
        $lastDataRow = $firstDataRow + count($this->rows) - 1;

        foreach ($this->headers as $i => $header) {
            $type = $this->columnTypes[$i] ?? 'string';
            $letter = Coordinate::stringFromColumnIndex($i + 1);

            $format = match ($type) {
                'currency' => '#,##0.00',
                'integer' => '#,##0',
                'date' => 'dd mmm yyyy',
                default => null,
            };

            if ($format !== null) {
                $sheet->getStyle("{$letter}{$firstDataRow}:{$letter}{$lastDataRow}")
                    ->getNumberFormat()->setFormatCode($format);
            }

            if ($type === 'quantity') {
                $this->formatQuantityCells($sheet, $i, $letter, $firstDataRow);
            }
        }
    }

    /**
     * Whole numbers read as "5" and fractions as "1.5". One format can't do
     * both: "#,##0.###" shows a whole number as "5." in Excel, and "#,##0"
     * shows 1.5 as 2. So each cell gets the one that suits its own value.
     */
    protected function formatQuantityCells($sheet, int $columnIndex, string $letter, int $firstDataRow): void
    {
        foreach (array_values($this->rows) as $offset => $row) {
            $value = $row[$columnIndex] ?? null;

            $sheet->getStyle($letter.($firstDataRow + $offset))->getNumberFormat()
                ->setFormatCode(is_numeric($value) && ! Quantity::isWhole($value) ? '#,##0.###' : '#,##0');
        }
    }

    public static function excelDate(?\DateTimeInterface $date): ?float
    {
        return $date ? ExcelDate::PHPToExcel($date) : null;
    }
}
