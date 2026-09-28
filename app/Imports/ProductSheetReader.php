<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;

/**
 * Deliberately does not implement WithHeadingRow or ToCollection/ToModel —
 * ProductImportService reads the raw sheet itself (every row as a plain
 * indexed array, header row included) so it has full control over
 * case-insensitive/trimmed header matching and precise "missing column"
 * errors, and so it only ever touches the FIRST sheet of a multi-sheet
 * workbook (the template's own "Example" sheet must never be imported).
 */
class ProductSheetReader implements WithCalculatedFormulas {}
