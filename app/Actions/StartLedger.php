<?php

namespace App\Actions;

use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerGate;
use App\Ledger\LedgerOpening;
use App\Ledger\LedgerSources;
use App\Ledger\Line;
use App\Models\LedgerBaseline;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sets up a shop's ledger, in steps that needn't hold the shop still. First,
 * changes start being noted. Then everything on record is captured as a baseline,
 * rather than posted, so a record changed partway is either captured as changed
 * or posts its difference. Then the opening entry goes in and posting begins. If
 * it's interrupted it can run again. After a freeze this opens a new epoch; the
 * old journal stays, read-only.
 */
class StartLedger
{
    /** @param array{cash?: mixed, accounts?: array<int, mixed>} $opening what the owner typed */
    public static function handle(Shop $shop, array $opening = []): Shop
    {
        $shop = self::begin($shop);

        self::captureBaselines($shop);

        return self::finish($shop, $opening);
    }

    protected static function begin(Shop $shop): Shop
    {
        return DB::transaction(function () use ($shop) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            if (! $shop->ledger_enabled) {
                throw ValidationException::withMessages(['ledger' => 'The ledger isn\'t switched on for this shop.']);
            }

            if ($shop->ledger_ready_at !== null && $shop->ledger_frozen_at === null) {
                throw ValidationException::withMessages(['ledger' => 'The ledger has already started.']);
            }

            ChartOfAccounts::ensureFor($shop);

            // Picking up an interrupted setup keeps its epoch.
            if ($shop->ledger_started_at === null || $shop->ledger_frozen_at !== null) {
                $shop->forceFill(['ledger_started_at' => now(), 'ledger_ready_at' => null, 'ledger_frozen_at' => null, 'ledger_epoch' => $shop->ledger_epoch + 1])->save();
            }

            LedgerBaseline::where('shop_id', $shop->id)->where('epoch', $shop->ledger_epoch)->delete();
            app(LedgerGate::class)->forget($shop->id);

            return $shop;
        });
    }

    protected static function captureBaselines(Shop $shop): void
    {
        foreach (LedgerSources::DOCUMENTS as $key => $source) {
            $source['model']::withoutGlobalScopes()->where('shop_id', $shop->id)->chunkById(500, function ($documents) use ($key, $shop) {
                $builder = LedgerSources::builder($key);
                $rows = [];

                foreach ($documents as $document) {
                    $lines = $builder->lines($document);

                    if ($lines !== []) {
                        $rows[] = ['shop_id' => $shop->id, 'epoch' => $shop->ledger_epoch, 'source_type' => $key, 'source_id' => $document->getKey(), 'lines' => json_encode(array_map(fn (Line $line) => $line->toArray(), $lines))];
                    }
                }

                LedgerBaseline::insert($rows);
            });
        }
    }

    protected static function finish(Shop $shop, array $opening): Shop
    {
        return DB::transaction(function () use ($shop, $opening) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            LedgerOpening::post($shop, LedgerOpening::fromBaselines($shop, $shop->ledger_epoch), $opening);

            $shop->forceFill(['ledger_ready_at' => now()])->save();

            return $shop;
        });
    }
}
