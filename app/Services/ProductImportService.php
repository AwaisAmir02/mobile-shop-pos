<?php

namespace App\Services;

use App\Actions\CreateAccessoryCategory;
use App\Actions\CreateProduct;
use App\Enums\LimitKey;
use App\Enums\QuantityUnit;
use App\Imports\ProductSheetReader;
use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\MainCategory;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Shop;
use App\Services\Billing\Entitlements;
use App\Support\Quantity;
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

    protected const OPTIONAL_HEADERS = ['cost price', 'stock qty', 'sub-category', 'brand', 'model', 'imei/serial', 'barcode', 'variant group', 'option 1', 'option 2', 'unit'];

    /** A small-edit-distance flag against the shop's own existing names — cheap (php levenshtein()), not exhaustive. */
    protected const SIMILARITY_THRESHOLD = 2;

    /** Read-only: parses and validates the file, creates nothing. Safe to call as many times as needed. */
    public function preview(string $absolutePath, int $shopId): array
    {
        $result = $this->analyze($absolutePath, $shopId);
        $summary = $this->summarize($result);
        $remaining = $this->remainingProductAllowance($shopId);
        $counted = $this->rowsCountingTowardLimit($result['rows'], $shopId);

        if ($remaining !== null && $counted > $remaining) {
            $summary['notices'][] = 'Your plan has room for '.number_format($remaining).' more product(s). The first '.number_format($remaining).' new row(s) will be imported and the other '.number_format($counted - $remaining).' will be skipped — archive old products, upgrade your plan or buy an add-on to import them.';
        }

        return $summary;
    }

    /** New products the rows add to the plan's count: a variant group counts once, however many rows it has. */
    protected function rowsCountingTowardLimit(array $rows, int $shopId): int
    {
        $new = collect($rows)->reject(fn (array $row) => $row['skip']);
        $groups = $new->pluck('groupName')->filter()->map($this->normalize(...))->unique();
        $counted = ProductGroup::withoutGlobalScopes()
            ->where('shop_id', $shopId)
            ->whereHas('variants', fn ($query) => $query->withoutGlobalScopes()->whereNull('archived_at'))
            ->pluck('name')
            ->map($this->normalize(...));

        return $new->whereNull('groupName')->count() + $groups->diff($counted)->count();
    }

    protected function remainingProductAllowance(int $shopId): ?int
    {
        $shop = Shop::find($shopId);

        return $shop ? app(Entitlements::class)->remaining($shop, LimitKey::Products) : null;
    }

    /**
     * Re-parses and re-validates the file from scratch (never trusts a
     * previously computed preview), then — only if still valid — performs
     * every write inside one transaction. A file that became invalid since
     * the preview (edited on disk, or re-checked and found to now
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
        $createdGroups = 0;
        $countedTowardLimit = 0;
        $limitSkipped = [];
        $remaining = $this->remainingProductAllowance($shopId);

        DB::transaction(function () use ($result, $shopId, $remaining, &$createdMainCategories, &$createdSubCategories, &$createdBrands, &$createdProducts, &$createdGroups, &$countedTowardLimit, &$limitSkipped) {
            /** @var array<string, array{id: int, slug: string}> $mainCategories keyed by normalized name */
            $mainCategories = [];
            /** @var array<string, true> $subCategorySeen keyed by "mainCategoryId|normalized sub name" */
            $subCategorySeen = [];
            /** @var array<string, true> $brandSeen keyed by normalized name */
            $brandSeen = [];
            /** @var array<string, ProductGroup> $groups keyed by normalized name */
            $groups = [];

            foreach ($result['rows'] as $row) {
                if ($row['skip']) {
                    continue;
                }

                $groupKey = $row['groupName'] !== null ? $this->normalize($row['groupName']) : null;

                if ($groupKey !== null && ! isset($groups[$groupKey])) {
                    $groups[$groupKey] = ProductGroup::withoutGlobalScopes()->where('shop_id', $shopId)->whereRaw('LOWER(TRIM(name)) = ?', [$groupKey])->first();
                }

                // A variant whose group already counts adds nothing to the plan's product count.
                $addsToCount = $groupKey === null || ($groups[$groupKey] ?? null) === null
                    || ! $groups[$groupKey]->variants()->withoutGlobalScopes()->whereNull('archived_at')->exists();

                if ($addsToCount && $remaining !== null && $countedTowardLimit >= $remaining) {
                    $limitSkipped[] = "Row {$row['excelRow']}: \"{$row['name']}\"";

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

                // Matched shop-wide: the table is UNIQUE(shop_id, name), whatever Main Category the name was made under.
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

                $details = CreateProduct::detailsForType(
                    $typeSlug,
                    $subCategoryName ?? '',
                    $brandName ?? '',
                    $row['model'] ?? '',
                    $row['imei'] ?? '',
                );

                if ($groupKey !== null && ($groups[$groupKey] ?? null) === null) {
                    $groups[$groupKey] = ProductGroup::create(['shop_id' => $shopId, 'name' => $row['groupName'], 'type' => $typeSlug, 'unit' => $row['unit'], 'details' => $details]);
                    $createdGroups++;
                }

                $product = new Product;
                $product->shop_id = $shopId;
                $product->fill([
                    'product_group_id' => $groupKey !== null ? $groups[$groupKey]->id : null,
                    'variant_label' => $row['variantLabel'],
                    'type' => $typeSlug,
                    // The group's own spelling, whatever casing this row used.
                    'name' => $groupKey !== null ? ProductGroupService::fullName($groups[$groupKey]->name, $row['variantLabel']) : $row['name'],
                    'barcode' => $row['barcode'],
                    'price' => $row['price'],
                    'cost_price' => $row['costPrice'],
                    'stock_quantity' => $row['stockQuantity'],
                    'unit' => $row['unit'],
                    'details' => $details,
                ]);
                $product->save();
                $createdProducts++;

                if ($addsToCount) {
                    $countedTowardLimit++;
                }
            }
        });

        return [
            'success' => true,
            'createdProducts' => $createdProducts,
            'skippedProducts' => count(array_filter($result['rows'], fn ($r) => $r['skip'])),
            'createdMainCategories' => $createdMainCategories,
            'createdSubCategories' => $createdSubCategories,
            'createdBrands' => $createdBrands,
            'createdGroups' => $createdGroups,
            'limitSkippedProducts' => count($limitSkipped),
            'limitSkippedLines' => array_slice($limitSkipped, 0, 50),
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
            // Includes an upload damaged or swapped between preview and confirm: a validation error, not a 500.
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

        // Compared in PHP: a JSON-column comparison in SQL ignores the app's collation on some MySQL versions.
        $existingProducts = Product::where('shop_id', $shopId)->get(['name', 'details', 'barcode']);
        $existingKeys = $existingProducts->map(fn (Product $p) => $this->normalize($p->name).'|'.$this->normalize((string) ($p->details['imei'] ?? '')))->flip();

        $barcodesEnabled = (bool) Shop::whereKey($shopId)->value('barcode_enabled');
        $existingBarcodeOwners = $existingProducts
            ->filter(fn (Product $p) => $p->barcode !== null && $p->barcode !== '')
            ->mapWithKeys(fn (Product $p) => [$this->normalize($p->barcode) => $p->name]);

        $notices = [];
        if (isset($headerIndex['barcode']) && ! $barcodesEnabled) {
            $notices[] = 'The Barcode column was ignored because barcode support is turned off for this shop.';
        }

        $variantsEnabled = (bool) Shop::whereKey($shopId)->value('variants_enabled');
        if (isset($headerIndex['variant group']) && ! $variantsEnabled) {
            $notices[] = 'The Variant Group, Option 1 and Option 2 columns were ignored because product variants are turned off for this shop.';
        }

        $unitsEnabled = (bool) Shop::whereKey($shopId)->value('measured_units_enabled');
        if (isset($headerIndex['unit']) && ! $unitsEnabled) {
            $notices[] = 'The Unit column was ignored because weighed and measured items are turned off for this shop.';
        }
        $seenGroupUnits = []; // normalized group name => the unit its first row settled on

        // Existing groups, by normalized name, so a row can join one but never disagree with its category.
        $existingGroups = $variantsEnabled
            ? ProductGroup::withoutGlobalScopes()->where('shop_id', $shopId)->get()->keyBy(fn (ProductGroup $group) => $this->normalize($group->name))
            : collect();
        $seenGroups = []; // normalized group name => ['main' => normalized, 'sub' => normalized], from the first row naming it
        $newGroupNames = []; // normalized group name => original-cased name

        $existingMainCategoryNames = MainCategory::query()->pluck('name')->all();
        $existingBrandNames = Brand::query()->pluck('name')->all();

        // UNIQUE(shop_id, name): a sub-category name exists once per shop, under whichever Main Category.
        $existingSubCategoryNames = AccessoryCategoryOption::query()->pluck('name')->all();

        // Mobile / Accessory is decided by the existing category's slug (what Product.type stores), not the row's free text.
        $mainCategorySlugByName = MainCategory::query()->get(['name', 'slug'])
            ->mapWithKeys(fn (MainCategory $c) => [$this->normalize($c->name) => $c->slug]);

        $rows = [];
        $seenBarcodes = []; // normalized barcode => first excel row number, rows that will be created only
        $seenInSheet = []; // "name|imei" => first excel row number
        $pendingMainCategories = []; // normalized name => original-cased name
        $pendingSubCategories = []; // "mainKey|subKey" => ['main' => name, 'sub' => name]
        $pendingBrands = []; // normalized name => original-cased name

        $dataRowCount = 0;

        // slice(1) keeps the 0-based keys, so key + 1 is the row number in Excel.
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
            $barcode = $barcodesEnabled ? trim($cellAt($cells, 'barcode')) : '';
            $groupName = $variantsEnabled ? trim($cellAt($cells, 'variant group')) : '';
            $options = array_values(array_filter([trim($cellAt($cells, 'option 1')), trim($cellAt($cells, 'option 2'))], fn (string $option) => $option !== ''));
            $variantLabel = $groupName !== '' ? implode(ProductGroupService::LABEL_SEPARATOR, $options) : '';

            $rowHadError = false;
            $addError = function (string $message) use (&$rowHadError, &$rowErrors, $excelRow) {
                $rowErrors[] = "Row {$excelRow}: {$message}";
                $rowHadError = true;
            };

            if ($groupName !== '') {
                if ($options === []) {
                    $addError('Option 1 or Option 2 is required because Variant Group is filled in.');
                } elseif (mb_strlen($variantLabel) > 100) {
                    $addError('The variant options are too long (100 characters at most together).');
                }

                if (mb_strlen($groupName) > 150) {
                    $addError('Variant Group is too long (150 characters at most).');
                }

                // The full name "Group - M / Red" is this row's identity, so Name may be left blank.
                $name = ProductGroupService::fullName($groupName, $variantLabel);
            }

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

            // A blank Unit on a variant row follows its group rather than disagreeing with it.
            $unitRaw = $unitsEnabled ? trim($cellAt($cells, 'unit')) : '';
            $groupKeyForUnit = $groupName !== '' ? $this->normalize($groupName) : null;
            $inheritedUnit = $groupKeyForUnit !== null ? ($existingGroups->get($groupKeyForUnit)?->unit ?? $seenGroupUnits[$groupKeyForUnit] ?? QuantityUnit::Piece) : QuantityUnit::Piece;
            $unit = $unitRaw === '' ? $inheritedUnit : QuantityUnit::fromInput($unitRaw);

            if ($unit === null) {
                $addError('Unit must be piece, kg, litre, metre, yard or gaz.');
                $unit = QuantityUnit::Piece;
            }

            $stockQuantity = 0;
            if ($stockRaw !== '') {
                if ($unit->allowsDecimals()) {
                    if (preg_match('/^\d+(\.\d{1,3})?$/', $stockRaw) !== 1 || (float) $stockRaw > Quantity::MAX) {
                        $addError('Stock Qty must be a number with at most 3 decimal places.');
                    } else {
                        $stockQuantity = Quantity::normalize($stockRaw);
                    }
                } elseif (! preg_match('/^\d+$/', $stockRaw) || (float) $stockRaw > 999999999) {
                    $addError('Stock Qty is not a valid whole number.');
                } else {
                    $stockQuantity = (int) $stockRaw;
                }
            }

            // A new Main Category is never mobile or accessory: ensureDefaultsExist() already made those two.
            $matchedSlug = $mainCategoryName !== '' ? ($mainCategorySlugByName[$this->normalize($mainCategoryName)] ?? null) : null;
            $isMobile = $matchedSlug === 'mobile';
            $isAccessory = $matchedSlug === 'accessory';

            if ($unit->allowsDecimals() && ($isMobile || $matchedSlug === 'sim')) {
                $addError('Mobiles and SIMs are sold by the piece.');
            }

            if ($isAccessory && $subCategoryName === '') {
                $addError('Sub-Category is required because Main Category is Accessory.');
            }

            if ($isMobile && $brandName === '') {
                $addError('Brand is required because Main Category is Mobile Phone.');
            }

            if (! $isMobile) {
                // Brand, Model and IMEI are mobile-only, as in CreateProduct::detailsForType(); ignored on other rows.
                $model = '';
                $imei = '';
                $brandName = '';
            }

            if ($groupName !== '') {
                $groupKey = $this->normalize($groupName);
                $mainKey = $this->normalize($mainCategoryName);
                $subKey = $this->normalize($subCategoryName);
                $existingGroup = $existingGroups->get($groupKey);

                if ($isMobile || $this->normalize($mainCategoryName) === 'sim' || $matchedSlug === 'sim') {
                    $addError("IMEI-tracked mobiles and SIMs can't have variants.");
                } elseif ($existingGroup !== null && ($matchedSlug !== $existingGroup->type || $subKey !== $this->normalize((string) ($existingGroup->details['category'] ?? '')))) {
                    $addError("Variant Group \"{$groupName}\" already exists under a different category.");
                } elseif (isset($seenGroups[$groupKey]) && $seenGroups[$groupKey] !== ['main' => $mainKey, 'sub' => $subKey]) {
                    $addError("Every row of Variant Group \"{$groupName}\" must have the same Main Category and Sub-Category.");
                } elseif ($existingGroup !== null && $existingGroup->unit !== $unit) {
                    $addError("Variant Group \"{$groupName}\" is sold by {$existingGroup->unit->symbol()}, and every variant in a group has to use the same unit.");
                } elseif (isset($seenGroupUnits[$groupKey]) && $seenGroupUnits[$groupKey] !== $unit) {
                    $addError("Every row of Variant Group \"{$groupName}\" must have the same Unit.");
                } else {
                    $seenGroups[$groupKey] = ['main' => $mainKey, 'sub' => $subKey];
                    $seenGroupUnits[$groupKey] = $unit;
                }
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

            $barcodeKey = $this->normalize($barcode);
            if (! $skip && $barcode !== '') {
                if (! preg_match('/^[\x20-\x7E]+$/', $barcode)) {
                    $rowErrors[] = "Row {$excelRow}: Barcode may only contain plain letters, digits and symbols.";

                    continue;
                }

                if (isset($seenBarcodes[$barcodeKey])) {
                    $rowErrors[] = "Row {$excelRow}: Barcode \"{$barcode}\" is also on row {$seenBarcodes[$barcodeKey]}.";

                    continue;
                }

                if (isset($existingBarcodeOwners[$barcodeKey])) {
                    $rowErrors[] = "Row {$excelRow}: Barcode \"{$barcode}\" is already used by \"{$existingBarcodeOwners[$barcodeKey]}\".";

                    continue;
                }

                $seenBarcodes[$barcodeKey] = $excelRow;
            }

            if (! $skip) {
                if ($groupName !== '' && ! $existingGroups->has($this->normalize($groupName))) {
                    $newGroupNames[$this->normalize($groupName)] ??= $groupName;
                }

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
                'barcode' => $barcode !== '' ? $barcode : null,
                'groupName' => $groupName !== '' ? $groupName : null,
                'variantLabel' => $groupName !== '' ? $variantLabel : null,
                'price' => $price,
                'costPrice' => $costPrice,
                'stockQuantity' => $stockQuantity,
                'unit' => $unit,
                'skip' => $skip,
                'skipReason' => $skip ? "already exists (same name and IMEI)" : null,
            ];
        }

        $valid = empty($fileErrors) && empty($rowErrors);

        return [
            'valid' => $valid,
            'notices' => $notices,
            'fileErrors' => $fileErrors,
            'rowErrors' => $rowErrors,
            'rows' => $valid ? $rows : [],
            'newMainCategoryNames' => array_values($pendingMainCategories),
            'newSubCategoryNames' => array_map(fn ($s) => "{$s['sub']} (under {$s['main']})", array_values($pendingSubCategories)),
            'newBrandNames' => array_values($pendingBrands),
            'newGroupNames' => array_values($newGroupNames),
            'similarWarnings' => $valid ? $this->similarityWarnings($pendingMainCategories, $existingMainCategoryNames, $pendingBrands, $existingBrandNames) : [],
        ];
    }

    protected function invalid(array $fileErrors): array
    {
        return [
            'valid' => false,
            'notices' => [],
            'fileErrors' => $fileErrors,
            'rowErrors' => [],
            'rows' => [],
            'newMainCategoryNames' => [],
            'newSubCategoryNames' => [],
            'newBrandNames' => [],
            'newGroupNames' => [],
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
            'notices' => $result['notices'],
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
            'newGroupNames' => $result['newGroupNames'],
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
    public function templateResponse(bool $withBarcode = true, bool $withVariants = false, bool $withUnits = false): StreamedResponse
    {
        $headers = ['Name', 'Main Category', 'Sub-Category', 'Brand', 'Model', 'IMEI/Serial', 'Selling Price', 'Cost Price', 'Stock Qty'];
        if ($withBarcode) {
            $headers[] = 'Barcode';
        }

        $barcodeColumn = $withBarcode ? chr(ord('A') + count($headers) - 1) : null;

        if ($withVariants) {
            array_push($headers, 'Variant Group', 'Option 1', 'Option 2');
        }

        if ($withUnits) {
            $headers[] = 'Unit';
        }

        $lastColumn = chr(ord('A') + count($headers) - 1);
        $columns = range('A', $lastColumn);

        $spreadsheet = new Spreadsheet;

        $products = $spreadsheet->getActiveSheet();
        $products->setTitle('Products');
        $products->fromArray($headers, null, 'A1');
        $products->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $products->getStyle('F:F')->getNumberFormat()->setFormatCode('@'); // IMEI/Serial as Text
        if ($barcodeColumn) {
            $products->getStyle("{$barcodeColumn}:{$barcodeColumn}")->getNumberFormat()->setFormatCode('@');
        }
        foreach ($columns as $letter) {
            $products->getColumnDimension($letter)->setWidth(18);
        }

        $example = $spreadsheet->createSheet();
        $example->setTitle('Example');
        $example->fromArray($headers, null, 'A1');
        $example->getStyle("A1:{$lastColumn}1")->getFont()->setBold(true);
        $example->getStyle('F:F')->getNumberFormat()->setFormatCode('@');
        if ($barcodeColumn) {
            $example->getStyle("{$barcodeColumn}:{$barcodeColumn}")->getNumberFormat()->setFormatCode('@');
        }
        $example->fromArray([
            'Galaxy A15', 'Mobile Phone', '', 'Samsung', 'A15', '351756051523999', '45000', '39000', '5',
            ...($withBarcode ? ['2000000000015'] : []),
        ], null, 'A2');
        $example->fromArray([
            'USB-C Cable', 'Accessory', 'Cable', '', '', '', '500', '300', '20',
            ...($withBarcode ? [''] : []),
        ], null, 'A3');

        if ($withVariants) {
            $example->fromArray([
                '', 'Clothing', '', '', '', '', '1500', '900', '10',
                ...($withBarcode ? [''] : []),
                'Cotton Shirt', 'M', 'Red',
            ], null, 'A4');
        }

        if ($withUnits) {
            $example->fromArray([
                'Basmati Rice', 'Grocery', '', '', '', '', '320', '280', '25.5',
                ...($withBarcode ? [''] : []),
                ...($withVariants ? ['', '', ''] : []),
                'kg',
            ], null, 'A5');
        }

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
            ['Stock Qty', 'Optional', $withUnits ? 'Defaults to 0 if left blank. A whole number for pieces; up to 3 decimals for every other unit.' : 'Whole number. Defaults to 0 if left blank.'],
            ...($withBarcode ? [['Barcode', 'Optional', 'Kept as text. Must be unique in your shop — a barcode already used by another product, or repeated on another row, is an error. Leave blank if the product has none.']] : []),
            ...($withVariants ? [
                ['Variant Group', 'Optional', 'Rows with the same Variant Group become variants of one product and count as one product on your plan. They must share a Main Category and Sub-Category. Not for Mobile Phone. Name can be left blank: the full name is built as "Cotton Shirt - M / Red", and a row matching an existing full name is skipped.'],
                ['Option 1, Option 2', 'At least one, when Variant Group is filled in', 'For example a size and a colour.'],
            ] : []),
            ...($withUnits ? [['Unit', 'Optional', 'piece, kg, litre, metre, yard or gaz. Blank means piece. Mobile phones are always pieces, and every variant in a group has to use the same unit.']] : []),
            ['', '', ''],
            ['Note:', '', 'Images cannot be imported from a spreadsheet — add product photos afterwards from the Products screen.'],
            ['Note:', '', 'A row whose Name and IMEI/Serial exactly match an existing product is skipped, not overwritten.'],
        ];
        $example->fromArray($notes, null, 'A7');
        $example->getStyle('A7:C7')->getFont()->setBold(true);
        foreach ($columns as $letter) {
            $example->getColumnDimension($letter)->setWidth(22);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, 'product-import-template.xlsx');
    }
}
