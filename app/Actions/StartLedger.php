<?php

namespace App\Actions;

use App\Ledger\ChartOfAccounts;
use App\Ledger\LedgerGate;
use App\Ledger\LedgerSources;
use App\Ledger\Line;
use App\Models\LedgerBaseline;
use App\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Begins a shop's ledger. Everything already recorded is captured as a baseline
 * rather than posted, so only changes from here on reach the journal. Restarting
 * after a freeze opens a new epoch; the old journal stays, read-only.
 */
class StartLedger
{
    public static function handle(Shop $shop): Shop
    {
        return DB::transaction(function () use ($shop) {
            $shop = Shop::whereKey($shop->id)->lockForUpdate()->firstOrFail();

            if (! $shop->ledger_enabled) {
                throw ValidationException::withMessages(['ledger' => 'The ledger isn\'t switched on for this shop.']);
            }

            if ($shop->ledger_started_at !== null && $shop->ledger_frozen_at === null) {
                throw ValidationException::withMessages(['ledger' => 'The ledger has already started.']);
            }

            ChartOfAccounts::ensureFor($shop);

            $epoch = $shop->ledger_epoch + 1;

            foreach (LedgerSources::DOCUMENTS as $key => $source) {
                $source['model']::withoutGlobalScopes()->where('shop_id', $shop->id)->chunkById(500, function ($documents) use ($key, $shop, $epoch) {
                    $builder = LedgerSources::builder($key);
                    $rows = [];

                    foreach ($documents as $document) {
                        $lines = $builder->lines($document);

                        if ($lines !== []) {
                            $rows[] = ['shop_id' => $shop->id, 'epoch' => $epoch, 'source_type' => $key, 'source_id' => $document->getKey(), 'lines' => json_encode(array_map(fn (Line $line) => $line->toArray(), $lines))];
                        }
                    }

                    LedgerBaseline::insert($rows);
                });
            }

            $shop->forceFill(['ledger_started_at' => now(), 'ledger_frozen_at' => null, 'ledger_epoch' => $epoch])->save();
            app(LedgerGate::class)->forget($shop->id);

            return $shop;
        });
    }
}
