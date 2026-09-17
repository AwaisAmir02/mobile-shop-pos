<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * For the handful of screens with more than one logical table to export
 * (the per-customer Udhaar page: Ledger + Other Amounts Owed; Party
 * Ledger: Earnings + Per-Customer Balances) — one workbook, one sheet per
 * section, each formatted identically to a standalone TableExport.
 *
 * @param  array<int, array{title: string, headers: array, rows: array, columnTypes: array}>  $sections
 */
class MultiTableExport implements WithMultipleSheets
{
    public function __construct(
        protected string $title,
        protected string $shopName,
        protected string $filtersSummary,
        protected array $sections,
    ) {}

    public function sheets(): array
    {
        return array_map(
            fn (array $section) => new TableExportSheet(
                $section['title'],
                $this->title,
                $this->shopName,
                $this->filtersSummary,
                $section['headers'],
                $section['rows'],
                $section['columnTypes'],
            ),
            $this->sections,
        );
    }
}
