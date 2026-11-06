<?php

namespace App\Ledger;

use App\Enums\RecordStatus;
use App\Ledger\ChartOfAccounts as Chart;
use App\Models\LedgerBaseline;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\Shop;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * The opening balances. What people and vendors owe comes from the same document
 * lines the poster later compares against, so opening and later postings can't
 * disagree. Inventory is today's stock at cost, with stock no batch covers at
 * the product's current cost. Cash and bank can only be typed: the records don't
 * know them. Opening Balances takes whatever's left, so any old error lands there,
 * visibly, rather than in a report.
 */
class LedgerOpening
{
    /** Balances carried from documents. Income, costs and past cash movements are history, not opening balances. */
    public const FROM_DOCUMENTS = [Chart::RECEIVABLE, Chart::PAYABLE, Chart::EMPLOYEE_ADVANCES, Chart::WITHHOLDINGS, Chart::CLEARING, Chart::CANCELLED_UNCONFIRMED];

    /** @return array<int, array{account: string, branch_id: ?int, party_type: ?string, party_id: ?int, paise: int}> */
    public static function fromBuilders(Shop $shop): array
    {
        $totals = [];

        foreach (LedgerSources::DOCUMENTS as $key => $source) {
            $builder = LedgerSources::builder($key);

            $source['model']::withoutGlobalScopes()->where('shop_id', $shop->id)->chunkById(500, function ($documents) use ($builder, &$totals) {
                foreach ($documents as $document) {
                    foreach ($builder->lines($document) as $line) {
                        self::add($totals, $line);
                    }
                }
            });
        }

        return array_values($totals);
    }

    /** @return array<int, array{account: string, branch_id: ?int, party_type: ?string, party_id: ?int, paise: int}> */
    public static function fromBaselines(Shop $shop, int $epoch): array
    {
        $totals = [];

        LedgerBaseline::where('shop_id', $shop->id)->where('epoch', $epoch)->chunkById(500, function ($baselines) use (&$totals) {
            foreach ($baselines as $baseline) {
                foreach ($baseline->lines as $line) {
                    self::add($totals, Line::fromArray($line));
                }
            }
        });

        return array_values($totals);
    }

    protected static function add(array &$totals, Line $line): void
    {
        if (! in_array($line->account, self::FROM_DOCUMENTS, true)) {
            return;
        }

        $key = implode('|', [$line->account, $line->branchId ?? '', $line->partyType ?? '', $line->partyId ?? '']);
        $totals[$key] ??= ['account' => $line->account, 'branch_id' => $line->branchId, 'party_type' => $line->partyType, 'party_id' => $line->partyId, 'paise' => 0];
        $totals[$key]['paise'] += $line->paise;
    }

    /** Stock at cost now: batches at their cost, the rest at the product's current cost. */
    public static function inventoryValue(int $shopId): float
    {
        $batches = DB::table('stock_ins')->where('shop_id', $shopId)->where('status', '!=', RecordStatus::Cancelled->value)->where('quantity_remaining', '>', 0)->get(['product_id', 'quantity_remaining', 'cost_price']);
        $value = $batches->sum(fn ($batch) => Quantity::money($batch->cost_price ?? 0, $batch->quantity_remaining));
        $inBatches = $batches->groupBy('product_id')->map(fn ($group) => Quantity::sum($group->pluck('quantity_remaining')));

        foreach (DB::table('products')->where('shop_id', $shopId)->get(['id', 'stock_quantity', 'cost_price']) as $product) {
            $uncovered = Quantity::subtract($product->stock_quantity, $inBatches[$product->id] ?? 0);
            $sign = Quantity::compare($uncovered, 0) < 0 ? -1 : 1;
            $value += $sign * Quantity::money($product->cost_price ?? 0, abs($uncovered));
        }

        return round($value, 2);
    }

    /**
     * @param  array<int, array{account: string, branch_id: ?int, party_type: ?string, party_id: ?int, paise: int}>  $documentBalances
     * @param  array{cash?: mixed, accounts?: array<int, mixed>}  $typed
     */
    public static function post(Shop $shop, array $documentBalances, array $typed): void
    {
        $lines = array_filter($documentBalances, fn (array $line) => $line['paise'] !== 0);

        $inventory = Line::paise(self::inventoryValue($shop->id));

        if ($inventory !== 0) {
            $lines[] = ['account' => Chart::INVENTORY, 'branch_id' => null, 'party_type' => null, 'party_id' => null, 'paise' => $inventory];
        }

        $cash = Line::paise($typed['cash'] ?? 0);

        if ($cash !== 0) {
            $lines[] = ['account' => Chart::CASH, 'branch_id' => null, 'party_type' => null, 'party_id' => null, 'paise' => $cash];
        }

        foreach ($typed['accounts'] ?? [] as $accountId => $amount) {
            if (Line::paise($amount) !== 0) {
                $lines[] = ['account' => Chart::bankCode((int) $accountId), 'branch_id' => null, 'party_type' => null, 'party_id' => null, 'paise' => Line::paise($amount)];
            }
        }

        if ($lines === []) {
            return;
        }

        $plug = -array_sum(array_column($lines, 'paise'));

        if ($plug !== 0) {
            $lines[] = ['account' => Chart::OPENING, 'branch_id' => null, 'party_type' => null, 'party_id' => null, 'paise' => $plug];
        }

        $date = $shop->ledger_started_at->toDateString();
        $accounts = Chart::idsFor($shop->id);
        $poster = app(LedgerPoster::class);

        $entry = LedgerEntry::create([
            'shop_id' => $shop->id,
            'epoch' => $shop->ledger_epoch,
            'entry_date' => $date,
            'source_type' => 'opening',
            'source_id' => null,
            'description' => 'Opening balances',
            'created_at' => now(),
        ]);

        foreach ($lines as $line) {
            LedgerLine::create([
                'shop_id' => $shop->id,
                'ledger_entry_id' => $entry->id,
                'ledger_account_id' => $accounts[$line['account']] ?? $poster->bankAccount($shop, $line['account']),
                'amount_paise' => $line['paise'],
                'party_type' => $line['party_type'],
                'party_id' => $line['party_id'],
                'branch_id' => $line['branch_id'],
                'origin_account_code' => $line['account'],
                'origin_date' => $date,
            ]);
        }
    }
}
