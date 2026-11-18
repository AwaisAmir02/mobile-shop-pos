<?php

namespace App\Services\LegacyImport;

use App\Actions\ConsumeStockFifo;
use App\Actions\CreateStockIn;
use App\Console\Commands\LegacyShopExtract;
use App\Enums\ShopModule;
use App\Enums\StockInPaymentSource;
use App\Models\AccessoryCategoryOption;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\MainCategory;
use App\Models\ModuleRevision;
use App\Models\Product;
use App\Models\Repair;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleRevision;
use App\Models\Shop;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\UdhaarTransaction;
use App\Models\User;
use App\Models\Vendor;
use App\Support\ApiShopContext;
use App\Support\Quantity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Brings one shop from an old install (a legacy:extract file) into an empty
 * shop here. Stock arrives through CreateStockIn and completed sales draw
 * stock through ConsumeStockFifo, replayed in business-date order. Prices,
 * costs, payments and dates are the recorded ones, never recomputed, so
 * historical totals and profit stay as they were.
 *
 * The caller holds the transaction and ApiShopContext; run() throws
 * LegacyImportException on anything it can't carry over exactly.
 */
class LegacyShopImporter
{
    public const FORMAT = 'legacy-shop-v1';

    /** Owner's decision (2026-11-18): turn these off, as on the old shop. Networks is part of SIM sales. */
    public const MODULES_TO_DISABLE = ['bills', 'wallet-loads', 'balance-loads', 'nadra-verifications', 'sim-sales', 'shop-accounts'];

    /**
     * The target must have none of these yet: they would change the stock
     * replay or the money totals the import is checked against. Customers and
     * repairs the owner already entered may stay (owner's decision,
     * 2026-11-18), as may categories, options and brands.
     */
    public const MUST_BE_EMPTY = ['products', 'vendors', 'sales', 'stock_ins', 'stock_in_payments', 'expenses', 'udhaar_transactions', 'sale_revisions', 'module_revisions'];

    /** The app's own names for the two built-in categories. */
    protected const BUILTIN_NAMES = ['mobile' => 'Mobile Phone', 'accessory' => 'Accessory'];

    protected int $ownerId;

    /** @var array<string, array<int|string, mixed>> old id => new id (or slug => slug) */
    protected array $map = ['users' => [], 'main_categories' => [], 'slugs' => [], 'options' => [], 'brands' => [], 'vendors' => [], 'customers' => [], 'products' => [], 'stock_ins' => [], 'sales' => [], 'udhaar' => []];

    /** @var array<string, mixed> */
    protected array $report = ['created' => [], 'reused' => [], 'categories' => [], 'modules' => [], 'notes' => []];

    public function __construct(protected array $data, protected Shop $shop)
    {
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new LegacyImportException('Not a legacy:extract file (format '.json_encode($data['format'] ?? null).').');
        }

        foreach (LegacyShopExtract::TABLES as $table) {
            if (! array_key_exists($table, $data['tables'] ?? [])) {
                throw new LegacyImportException("The file has no {$table} table.");
            }
        }
    }

    public static function fromFile(string $path, Shop $shop): self
    {
        if (! is_file($path)) {
            throw new LegacyImportException("No file at {$path}.");
        }

        return new self(json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR), $shop);
    }

    /** Reasons this shop can't take the import; empty when it can. Read-only. */
    public function blockers(string $expectedEmail): array
    {
        $blockers = [];
        $owner = User::where('shop_id', $this->shop->id)->where('is_owner', true)->orderBy('id')->first();
        $emails = array_map('mb_strtolower', array_filter([$this->shop->email, $owner?->email]));

        if (! in_array(mb_strtolower(trim($expectedEmail)), $emails, true)) {
            $blockers[] = "Shop {$this->shop->id}'s email is not {$expectedEmail}.";
        }

        if (! $owner) {
            $blockers[] = "Shop {$this->shop->id} has no owner user to record the history under.";
        }

        if (mb_strtolower((string) ($this->data['shop']['email'] ?? '')) !== mb_strtolower(trim($expectedEmail))) {
            $blockers[] = "The file is for {$this->data['shop']['email']}, not {$expectedEmail}.";
        }

        foreach (self::MUST_BE_EMPTY as $table) {
            if (($count = DB::table($table)->where('shop_id', $this->shop->id)->count()) > 0) {
                $blockers[] = "Shop {$this->shop->id} already has {$count} {$table} rows.";
            }
        }

        return $blockers;
    }

    /** @return array<string, mixed> what was done, for the report */
    public function run(): array
    {
        if (ApiShopContext::current()->shopId() !== $this->shop->id || DB::transactionLevel() === 0) {
            throw new LegacyImportException('run() needs an open transaction and ApiShopContext set to the target shop.');
        }

        $this->ownerId = (int) User::where('shop_id', $this->shop->id)->where('is_owner', true)->orderBy('id')->value('id');

        foreach ($this->data['users'] as $user) {
            $this->map['users'][$user['id']] = $this->ownerId;
        }

        $this->disableModules();
        $this->importCategories();
        $this->importOptions();
        $this->importBrands();
        $this->importSimple('vendors', Vendor::class, ['name', 'phone', 'company_name', 'address', 'notes']);
        $this->importSimple('customers', Customer::class, ['name', 'phone', 'address']);
        $this->importProducts();
        $this->replayStockAndSales();
        $this->importExpenses();
        $this->importUdhaar();
        $this->importRepairs();
        $this->importSaleRevisions();
        $this->importModuleRevisions();
        $this->assertEndState();
        $this->restoreTimestamps();

        return $this->report;
    }

    protected function rows(string $table): array
    {
        return $this->data['tables'][$table];
    }

    protected function user(?int $oldId): ?int
    {
        if ($oldId === null) {
            return null;
        }

        return $this->map['users'][$oldId] ?? throw new LegacyImportException("User {$oldId} is not one of the old shop's users.");
    }

    protected function mapped(string $kind, ?int $oldId, bool $nullable = true): ?int
    {
        if ($oldId === null) {
            return $nullable ? null : throw new LegacyImportException("A required {$kind} reference is empty.");
        }

        return $this->map[$kind][$oldId] ?? throw new LegacyImportException("No imported {$kind} for old id {$oldId}.");
    }

    /** Saves through the model (its hooks and shop/branch fill) with the old row's own timestamps. */
    protected function insert(Model $model, array $attributes): Model
    {
        $model->timestamps = false;
        $model->forceFill($attributes)->save();

        return $model;
    }

    protected function count(string $table, int $by = 1): void
    {
        $this->report['created'][$table] = ($this->report['created'][$table] ?? 0) + $by;
    }

    protected function disableModules(): void
    {
        $old = json_decode($this->data['shop']['disabled_screens'] ?? '[]', true) ?: [];
        $oldModules = collect($old)->map(fn (string $screen) => ShopModule::forScreen($screen)?->value ?? $screen)->unique()->values();

        $skipped = $oldModules->diff(self::MODULES_TO_DISABLE)->values()->all();
        $before = $this->shop->disabled_screens ?? [];
        $after = collect($before)->merge(self::MODULES_TO_DISABLE)->unique()->values()->all();

        $this->shop->disabled_screens = $after;
        $this->shop->save();

        $this->report['modules'] = [
            'old_screens' => $old,
            'before' => $before,
            'after' => $after,
            'hidden_on_old_but_left_on' => $skipped,
        ];
    }

    protected function importCategories(): void
    {
        // What the app does on a shop's first visit to Products, so the built-ins carry the app's names.
        MainCategory::ensureDefaultsExist($this->shop->id);

        $referenced = collect($this->rows('products'))->pluck('type')
            ->merge(collect($this->rows('sale_items'))->pluck('product_type'))
            ->merge(collect($this->rows('repairs'))->pluck('category'))
            ->filter()->countBy();

        $plan = [];

        foreach ($this->rows('main_categories') as $row) {
            $slug = $row['slug'];

            if (isset(self::BUILTIN_NAMES[$slug])) {
                $plan[] = [$row, self::BUILTIN_NAMES[$slug], $slug, "built-in: the shop's own name, same slug, so products and sales still match"];

                continue;
            }

            $natural = MainCategory::slugFor($row['name']);

            if ($natural === $slug) {
                $plan[] = [$row, $row['name'], $slug, 'as Techlinks had it'];
            } elseif ($referenced->has($slug)) {
                $plan[] = [$row, $row['name'], $slug, "kept Techlinks' slug: {$referenced[$slug]} products, sale lines or repairs use it"];
            } else {
                $plan[] = [$row, $row['name'], $natural, "slug corrected from '{$slug}' to match the name; nothing used the old slug"];
            }
        }

        $targets = array_column($plan, 2);

        if (count($targets) !== count(array_unique($targets))) {
            throw new LegacyImportException('Two categories would share a slug: '.implode(', ', array_diff_assoc($targets, array_unique($targets))));
        }

        foreach ($plan as [$row, $name, $slug, $why]) {
            $existing = MainCategory::where('slug', $slug)->first();

            if ($existing && ! isset(self::BUILTIN_NAMES[$slug]) && mb_strtolower($existing->name) !== mb_strtolower($name)) {
                throw new LegacyImportException("The shop already has a category with slug {$slug} named {$existing->name}, not {$name}.");
            }

            $category = $existing ?? $this->insert(new MainCategory, [
                'name' => $name, 'slug' => $slug, 'is_builtin' => isset(self::BUILTIN_NAMES[$slug]),
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ]);

            $existing ? $this->report['reused']['main_categories'][] = $category->name : $this->count('main_categories');

            $this->map['main_categories'][$row['id']] = $category->id;
            $this->map['slugs'][$row['slug']] = $category->slug;
            $this->report['categories'][] = ['old' => "{$row['name']} ({$row['slug']})", 'new' => "{$category->name} ({$category->slug})", 'why' => $why, 'existing' => (bool) $existing];
        }
    }

    protected function slug(?string $old): ?string
    {
        if ($old === null) {
            return null;
        }

        return $this->map['slugs'][$old] ?? throw new LegacyImportException("No category for slug {$old}.");
    }

    protected function importOptions(): void
    {
        foreach ($this->rows('accessory_category_options') as $row) {
            $mainId = $this->mapped('main_categories', $row['main_category_id']);
            $existing = AccessoryCategoryOption::where('main_category_id', $mainId)->where('name', $row['name'])->first();

            $option = $existing ?? $this->insert(new AccessoryCategoryOption, [
                'main_category_id' => $mainId, 'name' => $row['name'], 'image_path' => $row['image_path'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ]);

            $existing ? $this->report['reused']['accessory_category_options'][] = $option->name : $this->count('accessory_category_options');
            $this->map['options'][$row['id']] = $option->id;
        }
    }

    protected function importBrands(): void
    {
        foreach ($this->rows('brands') as $row) {
            $existing = Brand::where('name', $row['name'])->first();
            $brand = $existing ?? $this->insert(new Brand, ['name' => $row['name'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);

            $existing ? $this->report['reused']['brands'][] = $brand->name : $this->count('brands');
            $this->map['brands'][$row['id']] = $brand->id;
        }
    }

    /** @param class-string<Model> $class */
    protected function importSimple(string $table, string $class, array $columns): void
    {
        foreach ($this->rows($table) as $row) {
            $model = $this->insert(new $class, array_intersect_key($row, array_flip($columns)) + ['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
            $this->map[$table][$row['id']] = $model->id;
            $this->count($table);
        }
    }

    /**
     * Each product starts with the stock it had before the first replayed
     * event: its final stock, less what later stock-ins added, plus what
     * completed sales took. That opening stock has no batch behind it, as
     * stock from before batch tracking has here.
     */
    protected function importProducts(): void
    {
        $stockedIn = collect($this->rows('stock_ins'))->where('status', 'active')->whereNotNull('product_id')->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => Quantity::toThousandths($r['quantity'])));
        $completed = collect($this->rows('sales'))->where('status', 'completed')->pluck('id')->flip();
        $sold = collect($this->rows('sale_items'))->filter(fn ($i) => $completed->has($i['sale_id']) && $i['product_id'] !== null)->groupBy('product_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => Quantity::toThousandths($r['quantity'])));

        foreach ($this->rows('products') as $row) {
            $opening = Quantity::toThousandths($row['stock_quantity']) - ($stockedIn[$row['id']] ?? 0) + ($sold[$row['id']] ?? 0);

            if ($opening < 0) {
                throw new LegacyImportException("Product {$row['id']} ({$row['name']}) would open with negative stock.");
            }

            $details = $row['details'] === null ? null : json_decode($row['details'], true, 512, JSON_THROW_ON_ERROR);

            $product = $this->insert(new Product, [
                'type' => $this->slug($row['type']),
                'name' => $row['name'],
                'barcode' => $row['barcode'],
                'image_path' => $row['image_path'],
                'price' => $row['price'],
                'cost_price' => $row['cost_price'],
                'stock_quantity' => Quantity::fromThousandths($opening),
                'details' => $details,
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ]);

            $this->map['products'][$row['id']] = $product->id;
            $this->count('products');
        }

        $this->noteSubCategoriesWithoutOption();
    }

    /** Imported as they were, but editing such a product asks for a sub-category the list doesn't have. */
    protected function noteSubCategoriesWithoutOption(): void
    {
        $missing = [];

        foreach ($this->rows('products') as $row) {
            $category = json_decode((string) $row['details'], true)['category'] ?? null;

            if ($category === null || $category === '') {
                continue;
            }

            $mainId = MainCategory::where('slug', $this->slug($row['type']))->value('id');

            if (! AccessoryCategoryOption::where('main_category_id', $mainId)->where('name', $category)->exists()) {
                $missing[$row['type'].' / '.$category][] = $row['name'];
            }
        }

        foreach ($missing as $where => $names) {
            $this->report['notes'][] = 'Sub-category "'.$where.'" is on '.count($names).' products but not in the list, so editing them needs a sub-category picked: '.implode(', ', $names).'.';
        }
    }

    /** Stock-ins and sales in business-date order; on the same day a sale goes first, as nothing it sold was batched yet. */
    protected function replayStockAndSales(): void
    {
        $events = collect($this->rows('stock_ins'))->map(fn ($r) => ['key' => $r['stock_date'].' 1 '.str_pad((string) $r['id'], 10, '0', STR_PAD_LEFT), 'stock_in' => $r])
            ->merge(collect($this->rows('sales'))->map(fn ($r) => ['key' => substr($r['created_at'], 0, 10).' 0 '.$r['created_at'].' '.str_pad((string) $r['id'], 10, '0', STR_PAD_LEFT), 'sale' => $r]))
            ->sortBy('key')->values();

        $items = collect($this->rows('sale_items'))->groupBy('sale_id');
        $payments = collect($this->rows('sale_payments'))->groupBy('sale_id');

        foreach ($events as $event) {
            isset($event['stock_in'])
                ? $this->replayStockIn($event['stock_in'])
                : $this->replaySale($event['sale'], $items->get($event['sale']['id'], collect())->all(), $payments->get($event['sale']['id'], collect())->all());
        }
    }

    protected function replayStockIn(array $row): void
    {
        if ($row['status'] !== 'active') {
            throw new LegacyImportException("Stock-in {$row['id']} is {$row['status']}; only active stock-ins are handled.");
        }

        if ($row['product_id'] === null) {
            // Its product was deleted on the old install: kept as a batch with no product, as it was there.
            $entry = $this->insert(new StockIn, [
                'product_id' => null, 'vendor_id' => $this->mapped('vendors', $row['vendor_id']), 'product_name' => $row['product_name'],
                'user_id' => $this->user($row['user_id']), 'quantity' => $row['quantity'], 'cost_price' => $row['cost_price'],
                'selling_price' => $row['selling_price'], 'quantity_remaining' => $row['quantity_remaining'], 'total_cost' => $row['total_cost'],
                'payment_status' => $row['payment_status'], 'amount_paid' => $row['amount_paid'], 'stock_date' => $row['stock_date'],
                'note' => $row['note'], 'status' => 'active', 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ]);

            if (round((float) $row['amount_paid'], 2) != 0.0) {
                $this->insert(new StockInPayment, [
                    'stock_in_id' => $entry->id, 'user_id' => $this->user($row['user_id']), 'amount' => $row['amount_paid'],
                    'payment_date' => $row['stock_date'], 'source' => StockInPaymentSource::Initial,
                    'created_at' => $row['created_at'], 'updated_at' => $row['created_at'],
                ]);
                $this->count('stock_in_payments');
            }

            $this->report['notes'][] = "Stock-in {$row['id']} ({$row['product_name']}) has no product: kept as a batch with no product.";
        } else {
            $entry = CreateStockIn::handle(
                [['product_id' => $this->mapped('products', $row['product_id']), 'quantity' => $row['quantity'], 'cost_price' => $row['cost_price'] === null ? null : (float) $row['cost_price'], 'selling_price' => (float) $row['selling_price']]],
                $this->mapped('vendors', $row['vendor_id']),
                $row['payment_status'],
                (float) $row['amount_paid'],
                $row['stock_date'],
                $row['note'],
                $this->user($row['user_id']),
            )->first();

            foreach (['total_cost', 'amount_paid'] as $column) {
                if (round((float) $entry->{$column}, 2) !== round((float) $row[$column], 2)) {
                    throw new LegacyImportException("Stock-in {$row['id']}: {$column} came out {$entry->{$column}}, was {$row[$column]}.");
                }
            }

            if ($entry->payment_status->value !== $row['payment_status']) {
                throw new LegacyImportException("Stock-in {$row['id']}: payment status came out {$entry->payment_status->value}, was {$row['payment_status']}.");
            }

            DB::table('stock_ins')->where('id', $entry->id)->update(['created_at' => $row['created_at'], 'updated_at' => $row['updated_at']]);
            DB::table('stock_in_payments')->where('stock_in_id', $entry->id)->update(['created_at' => $row['created_at'], 'updated_at' => $row['created_at']]);
            $this->count('stock_in_payments', DB::table('stock_in_payments')->where('stock_in_id', $entry->id)->count());
        }

        $this->map['stock_ins'][$row['id']] = $entry->id;
        $this->count('stock_ins');
    }

    /**
     * A completed sale takes its stock as CreateSale does (lock, check, FIFO,
     * decrement) but keeps its recorded lines, payments and dates. A cancelled
     * one is stored as cancelled with no stock movement, which is where a
     * sale and its cancellation leave stock.
     */
    protected function replaySale(array $row, array $items, array $payments): void
    {
        if (! in_array($row['status'], ['completed', 'cancelled'], true)) {
            throw new LegacyImportException("Sale {$row['id']} has status {$row['status']}.");
        }

        $lines = [];

        foreach ($items as $item) {
            $productId = $this->mapped('products', $item['product_id']);
            $stockInId = null;

            if ($row['status'] === 'completed' && $productId !== null) {
                $product = Product::whereKey($productId)->lockForUpdate()->firstOrFail();
                $quantity = Quantity::normalize($item['quantity']);

                if (Quantity::compare($product->stock_quantity, $quantity) < 0) {
                    throw new LegacyImportException("Sale {$row['id']}: only {$product->stock_quantity} of {$product->name} in stock for {$quantity}.");
                }

                $allocations = ConsumeStockFifo::handle($product, $quantity);

                if (count($allocations) !== 1 || $allocations[0]['stock_in_id'] !== $this->mapped('stock_ins', $item['stock_in_id'])) {
                    throw new LegacyImportException("Sale {$row['id']}: {$product->name} would draw from different stock than it did (".json_encode($allocations).').');
                }

                $stockInId = $allocations[0]['stock_in_id'];
                $product->decrement('stock_quantity', $quantity);
            }

            $lines[] = [
                'product_id' => $productId,
                'stock_in_id' => $stockInId,
                'product_name' => $item['product_name'],
                'product_type' => $item['product_type'] === null ? null : ($this->map['slugs'][$item['product_type']] ?? $item['product_type']),
                'unit_price' => $item['unit_price'],
                'list_price' => $item['list_price'],
                'cost_price' => $item['cost_price'],
                'quantity' => $item['quantity'],
                'discount_amount' => $item['discount_amount'],
                'line_total' => $item['line_total'],
            ];
        }

        $sale = $this->insert(new Sale, [
            'user_id' => $this->user($row['user_id']),
            'customer_id' => $this->mapped('customers', $row['customer_id']),
            'subtotal' => $row['subtotal'],
            'discount_amount' => $row['discount_amount'],
            'total' => $row['total'],
            'status' => $row['status'],
            'cancelled_at' => $row['cancelled_at'],
            'cancelled_by' => $this->user($row['cancelled_by']),
            'cancel_reason' => $row['cancel_reason'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);

        foreach ($lines as $line) {
            $this->insert(new SaleItem, $line + ['sale_id' => $sale->id]);
            $this->count('sale_items');
        }

        foreach ($payments as $payment) {
            $this->insert(new SalePayment, [
                'sale_id' => $sale->id, 'user_id' => $this->user($payment['user_id']), 'amount' => $payment['amount'],
                'payment_date' => $payment['payment_date'], 'created_at' => $payment['created_at'], 'updated_at' => $payment['updated_at'],
            ]);
            $this->count('sale_payments');
        }

        $this->map['sales'][$row['id']] = $sale->id;
        $this->count('sales');
    }

    protected function cancellation(array $row): array
    {
        return ['status' => $row['status'], 'cancelled_at' => $row['cancelled_at'], 'cancelled_by' => $this->user($row['cancelled_by']), 'cancel_reason' => $row['cancel_reason']];
    }

    protected function importExpenses(): void
    {
        foreach ($this->rows('expenses') as $row) {
            $this->insert(new Expense, [
                'user_id' => $this->user($row['user_id']), 'description' => $row['description'], 'amount' => $row['amount'],
                'expense_date' => $row['expense_date'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ] + $this->cancellation($row));
            $this->count('expenses');
        }
    }

    protected function importUdhaar(): void
    {
        foreach ($this->rows('udhaar_transactions') as $row) {
            $transaction = $this->insert(new UdhaarTransaction, [
                'customer_id' => $this->mapped('customers', $row['customer_id'], false), 'user_id' => $this->user($row['user_id']),
                'type' => $row['type'], 'amount' => $row['amount'], 'transaction_date' => $row['transaction_date'], 'note' => $row['note'],
                'reverses_transaction_id' => $this->mapped('udhaar', $row['reverses_transaction_id']),
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ] + $this->cancellation($row));

            $this->map['udhaar'][$row['id']] = $transaction->id;
            $this->count('udhaar_transactions');
        }
    }

    protected function importRepairs(): void
    {
        foreach ($this->rows('repairs') as $row) {
            $this->insert(new Repair, [
                'user_id' => $this->user($row['user_id']), 'customer_id' => $this->mapped('customers', $row['customer_id']),
                'category' => $this->slug($row['category']), 'sub_category' => $row['sub_category'], 'description' => $row['description'],
                'amount' => $row['amount'], 'service_charges' => $row['service_charges'], 'products_charges' => $row['products_charges'],
                'discount' => $row['discount'], 'total' => $row['total'], 'payment_status' => $row['payment_status'], 'amount_paid' => $row['amount_paid'],
                'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            ] + $this->cancellation($row));
            $this->count('repairs');
        }
    }

    /** Snapshots point at the old ids; they're moved to the new ones so a revision still shows the right customer and products. */
    protected function remapSnapshot(array $snapshot): array
    {
        foreach (['customer_id' => 'customers', 'vendor_id' => 'vendors', 'product_id' => 'products', 'stock_in_id' => 'stock_ins'] as $key => $kind) {
            if (array_key_exists($key, $snapshot) && $snapshot[$key] !== null) {
                $snapshot[$key] = $this->mapped($kind, (int) $snapshot[$key]);
            }
        }

        if (isset($snapshot['items']) && is_array($snapshot['items'])) {
            $snapshot['items'] = array_map(fn ($item) => is_array($item) ? $this->remapSnapshot($item) : $item, $snapshot['items']);
        }

        return $snapshot;
    }

    protected function importSaleRevisions(): void
    {
        foreach ($this->rows('sale_revisions') as $row) {
            $this->insert(new SaleRevision, [
                'sale_id' => $this->mapped('sales', $row['sale_id'], false), 'action' => $row['action'], 'user_id' => $this->user($row['user_id']),
                'reason' => $row['reason'], 'snapshot' => $this->remapSnapshot(json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR)),
                'created_at' => $row['created_at'],
            ]);
            $this->count('sale_revisions');
        }
    }

    protected function importModuleRevisions(): void
    {
        foreach ($this->rows('module_revisions') as $row) {
            if ($row['revisionable_type'] !== StockIn::class) {
                throw new LegacyImportException("Module revision {$row['id']} is for {$row['revisionable_type']}; only stock-in revisions are handled.");
            }

            $this->insert(new ModuleRevision, [
                'revisionable_type' => $row['revisionable_type'], 'revisionable_id' => $this->mapped('stock_ins', $row['revisionable_id'], false),
                'action' => $row['action'], 'user_id' => $this->user($row['user_id']), 'reason' => $row['reason'],
                'snapshot' => $this->remapSnapshot(json_decode($row['snapshot'], true, 512, JSON_THROW_ON_ERROR)),
                'created_at' => $row['created_at'],
            ]);
            $this->count('module_revisions');
        }
    }

    /** Every product, batch and price ends exactly where the old install had it, or nothing is kept. */
    protected function assertEndState(): void
    {
        foreach ($this->rows('products') as $row) {
            $product = Product::whereKey($this->map['products'][$row['id']])->firstOrFail();

            if (Quantity::compare($product->stock_quantity, $row['stock_quantity']) !== 0) {
                throw new LegacyImportException("Product {$row['id']} ({$row['name']}) ends with {$product->stock_quantity} in stock, was {$row['stock_quantity']}.");
            }

            if (round((float) $product->price, 2) !== round((float) $row['price'], 2) || round((float) $product->cost_price, 2) !== round((float) $row['cost_price'], 2)) {
                throw new LegacyImportException("Product {$row['id']} ({$row['name']}) price or cost changed.");
            }
        }

        foreach ($this->rows('stock_ins') as $row) {
            $entry = StockIn::whereKey($this->map['stock_ins'][$row['id']])->firstOrFail();

            if (Quantity::compare($entry->quantity_remaining, $row['quantity_remaining']) !== 0) {
                throw new LegacyImportException("Stock-in {$row['id']} ends with {$entry->quantity_remaining} remaining, was {$row['quantity_remaining']}.");
            }
        }
    }

    /** Stock moves touched updated_at; put back the old install's. */
    protected function restoreTimestamps(): void
    {
        foreach ($this->rows('products') as $row) {
            DB::table('products')->where('id', $this->map['products'][$row['id']])->update(['updated_at' => $row['updated_at']]);
        }

        foreach ($this->rows('stock_ins') as $row) {
            DB::table('stock_ins')->where('id', $this->map['stock_ins'][$row['id']])->update(['updated_at' => $row['updated_at']]);
        }
    }

    public function map(): array
    {
        return $this->map;
    }
}
