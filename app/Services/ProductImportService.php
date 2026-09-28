<?php

namespace App\Services;

use App\Actions\CreateAccessoryCategory;
use App\Actions\CreateProduct;
use App\Imports\ProductSheetReader;
use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single place that knows how to turn an uploaded sheet into products —
 * used identically by the Products screen's preview step (analyze() only,
 * zero writes) and its confirm step (analyze() again, then the writes,
 * inside one transaction). Every write goes through CreateProduct::rules()/
 * detailsForType()/handle() and CreateAccessoryCategory::handle() — the
 * exact same calls the Add Product form itself makes — plus
 * MainCategory::uniqueSlugFor() for a brand-new Main Category's slug, so
 * an imported product is indistinguishable from one entered by hand.
 */
class ProductImportService
{
    public const MAX_FILE_SIZE_KB = 2048; // 2MB

    public const MAX_ROWS = 500;

    /** Canonical, lowercase header names this importer understands. */
    protected const REQUIRED_HEADERS = ['name', 'main category', 'selling price'];

    protected const OPTIONAL_HEADERS = ['cost price', 'stock qty', 'sub-category', 'brand', 'model', 'imei/serial'];

    /** A small-edit-distance flag against the shop's own existing names — cheap (php levenshtein()), not exhaustive. */
    protected const SIMILARITY_THRESHOLD = 2;

    /** Read-only: parses and validates the file, creates nothing. Safe to call as many times as needed. */
    public function preview(string $absolutePath, int $shopId): array
    {
        $result = $this->analyze($absolutePath, $shopId);

        return $this->summarize($result);
    }

    /**
     * Re-parses and re-validates the file from scratch (never trusts a
     * previously computed preview), then — only if still valid — performs
     * every write inside one transaction. A file that became invalid since
     * the preview (edited on disk, or simply re-checked and found to now
     * violate something) is refused the same way the preview would refuse
     * it, and nothing is written.
     */
    public function commit(string $absolutePath, int $shopId): array
    {
        $result = $this->analyze($absolutePath, $shopId);

        if (! $result['valid']) {
            return ['success' => false] + $this->summarize($result);
        }

        $createdMainCategories = 0;
        $createdSubCategories = 0;
        $createdBrands = 0;
        $createdProducts = 0;

        DB::transaction(function () use ($result, $shopId, &$createdMainCategories, &$createdSubCategories, &$createdBrands, &$createdProducts) {
            /** @var array<string, array{id: int, slug: string}> $mainCategories keyed by normalized name */
            $mainCategories = [];
            /** @var array<string, true> $subCategorySeen keyed by "mainCategoryId|normalized sub name" */
            $subCategorySeen = [];
            /** @var array<string, true> $brandSeen keyed by normalized name */
            $brandSeen = [];

            foreach ($result['rows'] as $row) {
                if ($row['skip']) {
                    continue;
                }

                $mainKey = $this->normalize($row['mainCategoryName']);
                if (! isset($mainCategories[$mainKey])) {
                    $existing = MainCategory::query()
                        ->whereRaw('LOWER(TRIM(name)) = ?', [$mainKey])
                        ->first();

                    if ($existing) {
                        $mainCategories[$mainKey] = ['id' => $existing->id, 'slug' => $existing->slug];
                    } else {
                        $category = MainCategory::create([
                            'name' => $row['mainCategoryName'],
                            'slug' => MainCategory::uniqueSlugFor($row['mainCategoryName']),
                        ]);
                        $mainCategories[$mainKey] = ['id' => $category->id, 'slug' => $category->slug];
                        $createdMainCategories++;
                    }
                }

                $mainCategoryId = $mainCategories[$mainKey]['id'];
                $typeSlug = $mainCategories[$mainKey]['slug'];

                // Shop-wide by name, per the table's actual UNIQUE(shop_id, name)
                // constraint — a sub-category name can only exist once per shop,
                // regardless of which Main Category it was originally created
                // under, so matching (and thus never attempting a duplicate
                // insert) must be shop-wide too, not scoped to this row's Main Category.
                $subCategoryName = null;
                if ($row['subCategoryName'] !== null) {
                    $subKey = $this->normalize($row['subCategoryName']);

                    if (! isset($subCategorySeen[$subKey])) {
                        $existingSub = AccessoryCategoryOption::query()
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$subKey])
                            ->first();

                        if (! $existingSub) {
                            CreateAccessoryCategory::handle($row['subCategoryName'], $mainCategoryId);
                            $createdSubCategories++;
                        }

                        $subCategorySeen[$subKey] = true;
                    }

                    $subCategoryName = $row['subCategoryName'];
                }

                $brandName = null;
                if ($row['brandName'] !== null) {
                    $brandKey = $this->normalize($row['brandName']);

                    if (! isset($brandSeen[$brandKey])) {
                        $existingBrand = Brand::query()
                            ->whereRaw('LOWER(TRIM(name)) = ?', [$brandKey])
                            ->first();

                        if (! $existingBrand) {
                            Brand::create(['name' => $row['brandName']]);
                            $createdBrands++;
                        }

                        $brandSeen[$brandKey] = true;
                    }

                    $brandName = $row['brandName'];
                }

                $product = new Product;
                $product->shop_id = $shopId;
                $product->fill([
                    'type' => $typeSlug,
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'cost_price' => $row['costPrice'],
                    'stock_quantity' => $row['stockQuantity'],
                    'details' => CreateProduct::detailsForType(
                        $typeSlug,
                        $subCategoryName ?? '',
                        $brandName ?? '',
                        $row['model'] ?? '',
                        $row['imei'] ?? '',
                    ),
                ]);
                $product->save();
                $createdProducts++;
            }
        });

        return [
            'success' => true,
            'createdProducts' => $createdProducts,
            'skippedProducts' => count(array_filter($result['rows'], fn ($r) => $r['skip'])),
            'createdMainCategories' => $createdMainCategories,
            'createdSubCategories' => $createdSubCategories,
            'createdBrands' => $createdBrands,
        ];
    }

    protected function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }

    /**
     * The shared read+validate+resolve pass. Returns everything preview()
     * and commit() each need, without ever writing to the database.
     */
    protected function analyze(string $absolutePath, int $shopId): array
    {
        $fileErrors = [];
        $rowErrors = [];

        try {
            $sheets = Excel::toCollection(new ProductSheetReader, $absolutePath);
        } catch (\Throwable $e) {
            // A genuinely corrupt/unreadable file — including the stored
            // upload having been tampered with or damaged between preview
            // and confirm — must fail validation cleanly, not surface a 500.
            return $this->invalid(['Could not read this file. It may be corrupted — please re-export it and upload again.']);
        }

        $sheet = $sheets->first() ?? collect();

        if ($sheet->isEmpty()) {
            return $this->invalid(['The file has no rows at all.']);
        }

        $headerRow = $sheet->first();
        $headerIndex = [];
        foreach ($headerRow as $i => $cell) {
            $key = $this->normalize((string) $cell);
            if ($key !== '') {
                $headerIndex[$key] = $i;
            }
        }

        $missingHeaders = array_values(array_diff(self::REQUIRED_HEADERS, array_keys($headerIndex)));
        if (! empty($missingHeaders)) {
            return $this->invalid(array_map(
                fn ($h) => 'Missing required column: "'.ucwords($h).'".',
                $missingHeaders
            ));
        }

        $dataRows = $sheet->slice(1);

        $cellAt = function ($cells, string $header) use ($headerIndex) {
            if (! isset($headerIndex[$header])) {
                return '';
            }

            $raw = $cells[$headerIndex[$header]] ?? null;

            return $this->cellToString($raw);
        };

        // Existing products for this shop, for the name+IMEI duplicate check — done in PHP
        // rather than a JSON-column SQL comparison, which can silently ignore the app's
        // collation depending on the MySQL version.
        $existingProducts = Product::where('shop_id', $shopId)->get(['name', 'details']);
        $existingKeys = $existingProducts->map(fn (Product $p) => $this->normalize($p->name).'|'.$this->normalize((string) ($p->details['imei'] ?? '')))->flip();

        $existingMainCategoryNames = MainCategory::query()->pluck('name')->all();
        $existingBrandNames = Brand::query()->pluck('name')->all();

        // accessory_category_options has a UNIQUE(shop_id, name) constraint —
        // a sub-category name is unique for the whole SHOP, not per Main
        // Category (confirmed against the actual migration, not assumed).
        // So "does this one already exist" is a flat shop-wide name check,
        // same as Brand — a name that exists under a different Main
        // Category than the row specifies is still the same row it must
        // match against, since the database can't hold two.
        $existingSubCategoryNames = AccessoryCategoryOption::query()->pluck('name')->all();

        // Keyed by normalized name => slug, so "is this row's Main Category
        // Mobile Phone / Accessory" is decided from the ACTUAL existing
        // category's slug (the same identity Product.type stores), not a
        // hopeful string comparison against the row's own free-text name.
        $mainCategorySlugByName = MainCategory::query()->get(['name', 'slug'])
            ->mapWithKeys(fn (MainCategory $c) => [$this->normalize($c->name) => $c->slug]);

        $rows = [];
        $seenInSheet = []; // "name|imei" => first excel row number
        $pendingMainCategories = []; // normalized name => original-cased name
        $pendingSubCategories = []; // "mainKey|subKey" => ['main' => name, 'sub' => name]
        $pendingBrands = []; // normalized name => original-cased name

        $dataRowCount = 0;

        // slice(1) keeps the sheet's original 0-based keys (header = 0), so
        // the first data row's key is already 1 — the exact Excel row
        // number it appears on (header occupies row 1).
        foreach ($dataRows as $offset => $cells) {
            $excelRow = $offset + 1;

            $isBlank = collect($cells)->every(fn ($c) => trim((string) $c) === '');
            if ($isBlank) {
                continue;
            }

            $dataRowCount++;
            if ($dataRowCount > self::MAX_ROWS) {
                return $this->invalid(['This file has more than '.self::MAX_ROWS.' rows. Split it into smaller files and import them separately.']);
            }

            $name = trim($cellAt($cells, 'name'));
            $mainCategoryName = trim($cellAt($cells, 'main category'));
            $priceRaw = $cellAt($cells, 'selling price');
            $costPriceRaw = $cellAt($cells, 'cost price');
            $stockRaw = $cellAt($cells, 'stock qty');
            $subCategoryName = trim($cellAt($cells, 'sub-category'));
            $brandName = trim($cellAt($cells, 'brand'));
            $model = trim($cellAt($cells, 'model'));
            $imei = trim($cellAt($cells, 'imei/serial'));

            $rowHadError = false;
            $addError = function (string $message) use (&$rowHadError, &$rowErrors, $excelRow) {
                $rowErrors[] = "Row {$excelRow}: {$message}";
                $rowHadError = true;
            };

            if ($name === '') {
                $addError('Name is required.');
            }

            if ($mainCategoryName === '') {
                $addError('Main Category is required.');
            }

            $price = $this->parseNumber($priceRaw);
            if ($priceRaw === '' ) {
                $addError('Selling Price is required.');
            } elseif ($price === null || $price < 0) {
                $addError('Selling Price is not a valid number.');
            }

            $costPrice = null;
            if ($costPriceRaw !== '') {
                $costPrice = $this->parseNumber($costPriceRaw);
                if ($costPrice === null || $costPrice < 0) {
                    $addError('Cost Price is not a valid number.');
                }
            }

            $stockQuantity = 0;
            if ($stockRaw !== '') {
                if (! preg_match('/^\d+$/', $stockRaw)) {
                    $addError('Stock Qty is not a valid whole number.');
                } else {
                    $stockQuantity = (int) $stockRaw;
                }
            }

            // A brand-new Main Category can never resolve to 'mobile'/'accessory' —
            // those two slugs are guaranteed to already exist (ensureDefaultsExist()),
            // so only an actual name match against an EXISTING category can be either.
            $matchedSlug = $mainCategoryName !== '' ? ($mainCategorySlugByName[$this->normalize($mainCategoryName)] ?? null) : null;
            $isMobile = $matchedSlug === 'mobile';
            $isAccessory = $matchedSlug === 'accessory';

            if ($isAccessory && $subCategoryName === '') {
                $addError('Sub-Category is required because Main Category is Accessory.');
            }

            if ($isMobile && $brandName === '') {
                $addError('Brand is required because Main Category is Mobile Phone.');
            }

            if (! $isMobile) {
                // Brand/Model/IMEI are mobile-only — matches CreateProduct::detailsForType(),
                // which never stores them for other types. A stray value in one of these
                // columns on a non-mobile row is ignored rather than creating an unused Brand.
                $model = '';
                $imei = '';
                $brandName = '';
            }

            if ($rowHadError) {
                continue;
            }

            // In-sheet duplicate: same trimmed/lowercased name AND same normalized IMEI (blank = blank).
            $dupKey = $this->normalize($name).'|'.$this->normalize($imei);
            if (isset($seenInSheet[$dupKey])) {
                $fileErrors[] = "Rows {$seenInSheet[$dupKey]} and {$excelRow} both have Name \"{$name}\"".($imei !== '' ? " and IMEI \"{$imei}\"" : '').' — remove one before importing.';

                continue;
            }
            $seenInSheet[$dupKey] = $excelRow;

            $skip = isset($existingKeys[$dupKey]);

            if (! $skip) {
                $mainKey = $this->normalize($mainCategoryName);
                if (! in_array($mainKey, array_map($this->normalize(...), $existingMainCategoryNames), true)) {
                    $pendingMainCategories[$mainKey] = $mainCategoryName;
                }

                if ($subCategoryName !== '') {
                    $subKey = $this->normalize($subCategoryName);
                    $existsAlready = in_array($subKey, array_map($this->normalize(...), $existingSubCategoryNames), true);
                    if (! $existsAlready && ! isset($pendingSubCategories[$subKey])) {
                        $pendingSubCategories[$subKey] = ['main' => $mainCategoryName, 'sub' => $subCategoryName];
                    }
                }

                if ($brandName !== '') {
                    $brandKey = $this->normalize($brandName);
                    if (! in_array($brandKey, array_map($this->normalize(...), $existingBrandNames), true)) {
                        $pendingBrands[$brandKey] = $brandName;
                    }
                }
            }

            $rows[] = [
                'excelRow' => $excelRow,
                'name' => $name,
                'mainCategoryName' => $mainCategoryName,
                'subCategoryName' => $subCategoryName !== '' ? $subCategoryName : null,
                'brandName' => $brandName !== '' ? $brandName : null,
                'model' => $model !== '' ? $model : null,
                'imei' => $imei !== '' ? $imei : null,
                'price' => $price,
                'costPrice' => $costPrice,
                'stockQuantity' => $stockQuantity,
                'skip' => $skip,
                'skipReason' => $skip ? "already exists (same name and IMEI)" : null,
            ];
        }

        $valid = empty($fileErrors) && empty($rowErrors);

        return [
            'valid' => $valid,
            'fileErrors' => $fileErrors,
            'rowErrors' => $rowErrors,
            'rows' => $valid ? $rows : [],
            'newMainCategoryNames' => array_values($pendingMainCategories),
            'newSubCategoryNames' => array_map(fn ($s) => "{$s['sub']} (under {$s['main']})", array_values($pendingSubCategories)),
            'newBrandNames' => array_values($pendingBrands),
            'similarWarnings' => $valid ? $this->similarityWarnings($pendingMainCategories, $existingMainCategoryNames, $pendingBrands, $existingBrandNames) : [],
        ];
    }

    protected function invalid(array $fileErrors): array
    {
        return [
            'valid' => false,
            'fileErrors' => $fileErrors,
            'rowErrors' => [],
            'rows' => [],
            'newMainCategoryNames' => [],
            'newSubCategoryNames' => [],
            'newBrandNames' => [],
            'similarWarnings' => [],
        ];
    }

    /** @return array<int, array{fileErrors: int, rowErrors: array<string>, ...}> shaped for display, with row-level lists capped so a huge bad file never bloats the response. */
    protected function summarize(array $result): array
    {
        $cap = 50;

        $toCreate = collect($result['rows'])->reject(fn ($r) => $r['skip']);
        $toSkip = collect($result['rows'])->filter(fn ($r) => $r['skip']);

        return [
            'valid' => $result['valid'],
            'fileErrors' => array_slice($result['fileErrors'], 0, $cap),
            'fileErrorsMore' => max(0, count($result['fileErrors']) - $cap),
            'rowErrors' => array_slice($result['rowErrors'], 0, $cap),
            'rowErrorsMore' => max(0, count($result['rowErrors']) - $cap),
            'toCreateCount' => $toCreate->count(),
            'toSkipCount' => $toSkip->count(),
            'skippedLines' => $toSkip->take($cap)->map(fn ($r) => "Row {$r['excelRow']}: \"{$r['name']}\" — {$r['skipReason']}")->all(),
            'skippedLinesMore' => max(0, $toSkip->count() - $cap),
            'newMainCategoryNames' => $result['newMainCategoryNames'],
            'newSubCategoryNames' => $result['newSubCategoryNames'],
            'newBrandNames' => $result['newBrandNames'],
            'similarWarnings' => $result['similarWarnings'],
        ];
    }

    protected function similarityWarnings(array $pendingMain, array $existingMain, array $pendingBrand, array $existingBrand): array
    {
        $warnings = [];

        foreach ($pendingMain as $newName) {
            foreach ($existingMain as $existingName) {
                if (levenshtein(mb_strtolower($newName), mb_strtolower($existingName)) <= self::SIMILARITY_THRESHOLD) {
                    $warnings[] = "New Main Category \"{$newName}\" is very close to existing \"{$existingName}\" — check for a typo.";
                }
            }
        }

        foreach ($pendingBrand as $newName) {
            foreach ($existingBrand as $existingName) {
                if (levenshtein(mb_strtolower($newName), mb_strtolower($existingName)) <= self::SIMILARITY_THRESHOLD) {
                    $warnings[] = "New Brand \"{$newName}\" is very close to existing \"{$existingName}\" — check for a typo.";
                }
            }
        }

        return $warnings;
    }

    protected function parseNumber(string $raw): ?float
    {
        $cleaned = preg_replace('/[^0-9.]/', '', $raw);

        if ($cleaned === '' || $cleaned === '.' || ! is_numeric($cleaned)) {
            return null;
        }

        return (float) $cleaned;
    }

    /** Coerces a cell to a plain string, formatting a numeric cell without scientific notation rather than trusting PHP's default float-to-string cast. */
    protected function cellToString($raw): string
    {
        if ($raw === null) {
            return '';
        }

        if (is_float($raw) || is_int($raw)) {
            return rtrim(rtrim(sprintf('%.10F', $raw), '0'), '.');
        }

        return (string) $raw;
    }

    /**
     * A "Products" sheet with the header row only (so a shopkeeper can
     * never accidentally import a leftover example row) plus a Text-
     * formatted IMEI/Serial column, and a separate "Example" sheet with a
     * worked row and the required/optional notes — the importer only ever
     * reads the first sheet.
     */
    public function templateResponse(): StreamedResponse
    {
        $headers = ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'];

        $spreadsheet = new Spreadsheet;

        $products = $spreadsheet->getActiveSheet();
        $products->setTitle('Products');
        $products->fromArray($headers, null, 'A1');
        $products->getStyle('A1:I1')->getFont()->setBold(true);
        $products->getStyle('F:F')->getNumberFormat()->setFormatCode('@'); // IMEI/Serial as Text
        foreach (range('A', 'I') as $letter) {
            $products->getColumnDimension($letter)->setWidth(18);
        }

        $example = $spreadsheet->createSheet();
        $example->setTitle('Example');
        $example->fromArray($headers, null, 'A1');
        $example->getStyle('A1:I1')->getFont()->setBold(true);
        $example->getStyle('F:F')->getNumberFormat()->setFormatCode('@');
        $example->fromArray([
            'Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '45000', '39000', '5',
        ], null, 'A2');
        $example->fromArray([
            'USB-C Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '20',
        ], null, 'A3');

        $notes = [
            ['Column', 'Required?', 'Notes'],
            ['Name', 'Required', ''],
            ['Main Category', 'Required', 'Matched by name (case/spacing don\'t matter). A name that doesn\'t exist yet is created.'],
            ['Sub-Category', 'Required only when Main Category is Accessory', 'Optional for every other Main Category. Created if it doesn\'t exist yet.'],
            ['Brand', 'Required only when Main Category is Mobile Phone', 'Ignored for every other Main Category. Created if it doesn\'t exist yet.'],
            ['Model', 'Optional, Mobile Phone only', 'Ignored for every other Main Category.'],
            ['IMEI/Serial', 'Optional, Mobile Phone only', 'Kept as text — do not reformat this column as a number.'],
            ['Selling Price', 'Required', 'Numbers only ("Rs"/commas are stripped automatically).'],
            ['Cost Price', 'Optional', ''],
            ['Stock Qty', 'Optional', 'Whole number. Defaults to 0 if left blank.'],
            ['', '', ''],
            ['Note:', '', 'Images cannot be imported from a spreadsheet — add product photos afterwards from the Products screen.'],
            ['Note:', '', 'A row whose Name and IMEI/Serial exactly match an existing product is skipped, not overwritten.'],
        ];
        $example->fromArray($notes, null, 'A6');
        $example->getStyle('A6:C6')->getFont()->setBold(true);
        foreach (range('A', 'I') as $letter) {
            $example->getColumnDimension($letter)->setWidth(22);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'product-import-template.xlsx');
    }
}
