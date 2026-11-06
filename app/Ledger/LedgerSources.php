<?php

namespace App\Ledger;

use App\Models\Expense;
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
    ];

    /** @var array<class-string<Model>, array{0: string, 1: string}> child model => [document key, foreign key] */
    public const CHILDREN = [];

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
