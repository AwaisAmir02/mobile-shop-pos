<?php

namespace App\Ledger;

use App\Models\Expense;
use App\Models\PartyLedgerEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\StockIn;
use App\Models\StockInPayment;
use App\Models\StockWriteOff;
use App\Models\UdhaarTransaction;
use App\Models\VendorInvoiceLine;
use Illuminate\Database\Eloquent\Model;

/**
 * Which models feed the ledger. A document is what posts; a child (a sale's item,
 * say) just marks its parent as changed. Each stage adds to this list.
 */
class LedgerSources
{
    /** @var array<string, array{model: class-string<Model>, builder: class-string<EntryBuilder>}> */
    public const DOCUMENTS = [
        'expense' => ['model' => Expense::class, 'builder' => Builders\ExpenseBuilder::class],
        'sale' => ['model' => Sale::class, 'builder' => Builders\SaleBuilder::class],
        'stock_in' => ['model' => StockIn::class, 'builder' => Builders\StockInBuilder::class],
        'stock_write_off' => ['model' => StockWriteOff::class, 'builder' => Builders\StockWriteOffBuilder::class],
        'udhaar' => ['model' => UdhaarTransaction::class, 'builder' => Builders\UdhaarBuilder::class],
        'party_ledger' => ['model' => PartyLedgerEntry::class, 'builder' => Builders\PartyLedgerBuilder::class],
        'product_stock' => ['model' => Product::class, 'builder' => Builders\ProductStockBuilder::class],
    ];

    /** @var array<class-string<Model>, array{0: string, 1: string}> child model => [document key, foreign key] */
    public const CHILDREN = [
        SaleItem::class => ['sale', 'sale_id'],
        SalePayment::class => ['sale', 'sale_id'],
        StockInPayment::class => ['stock_in', 'stock_in_id'],
        VendorInvoiceLine::class => ['stock_in', 'stock_in_id'],
    ];

    /** @return array<int, class-string<Model>> */
    public static function observed(): array
    {
        return [...array_column(self::DOCUMENTS, 'model'), ...array_keys(self::CHILDREN)];
    }

    /** @return array{0: string, 1: int}|null [document key, id] */
    public static function documentFor(Model $model): ?array
    {
        if (isset(self::CHILDREN[$model::class])) {
            [$key, $foreignKey] = self::CHILDREN[$model::class];

            return $model->{$foreignKey} !== null ? [$key, (int) $model->{$foreignKey}] : null;
        }

        foreach (self::DOCUMENTS as $key => $document) {
            if ($document['model'] === $model::class) {
                return [$key, (int) $model->getKey()];
            }
        }

        return null;
    }

    public static function builder(string $key): EntryBuilder
    {
        return app(self::DOCUMENTS[$key]['builder']);
    }

    /** Sees cancelled rows and every branch: a posting must not depend on who's looking. */
    public static function find(string $key, int $id): ?Model
    {
        return self::DOCUMENTS[$key]['model']::withoutGlobalScopes()->find($id);
    }
}
