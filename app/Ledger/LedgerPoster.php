<?php

namespace App\Ledger;

use App\Models\LedgerAccount;
use App\Models\LedgerBaseline;
use App\Models\LedgerEntry;
use App\Models\LedgerLine;
use App\Models\LedgerOutbox;
use App\Models\Shop;
use App\Models\ShopAccount;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Turns changed documents into journal entries. For each one it works out the
 * lines it should have now, takes away what it already had (its baseline from
 * when the ledger started, plus anything posted since) and posts the difference
 * as a new entry. Edits, cancels and corrections need no code of their own, and
 * running it twice posts nothing. Lines are never changed: a difference is a new
 * entry. It reads source tables and writes only ledger tables.
 */
class LedgerPoster
{
    public const MAX_ATTEMPTS = 5;

    /** @return int how many documents were processed successfully */
    public function drain(?int $shopId = null, int $limit = 500): int
    {
        $ids = LedgerOutbox::query()
            ->when($shopId, fn ($query) => $query->where('shop_id', $shopId))
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $done = 0;

        foreach ($ids as $id) {
            try {
                DB::transaction(function () use ($id, &$done) {
                    $row = LedgerOutbox::whereKey($id)->lockForUpdate()->first();

                    if ($row === null) {
                        return;
                    }

                    $this->post($row->shop_id, $row->source_type, $row->source_id);
                    $row->delete();
                    $done++;
                });
            } catch (Throwable $e) {
                // A failure stays in the backlog, visible on the ledger's screens. The sale it came from is unaffected.
                LedgerOutbox::whereKey($id)->update(['attempts' => DB::raw('attempts + 1'), 'last_error' => mb_substr($e->getMessage(), 0, 1000)]);
                report($e);
            }
        }

        return $done;
    }

    public function post(int $shopId, string $key, int $id): void
    {
        $shop = Shop::findOrFail($shopId);

        // Frozen or off while this waited: the ledger stays exactly as it was.
        if (! $shop->ledger_enabled || $shop->ledger_started_at === null || $shop->ledger_frozen_at !== null) {
            return;
        }

        $builder = LedgerSources::builder($key);
        $document = LedgerSources::find($key, $id);
        $accounts = ChartOfAccounts::idsFor($shopId);

        $difference = [];

        foreach ($document !== null ? $builder->lines($document) : [] as $line) {
            $this->add($difference, $line, $line->paise);
        }

        $baseline = LedgerBaseline::where('shop_id', $shopId)->where('epoch', $shop->ledger_epoch)->where('source_type', $key)->where('source_id', $id)->first();

        foreach ($baseline->lines ?? [] as $line) {
            $line = Line::fromArray($line);
            $this->add($difference, $line, -$line->paise);
        }

        foreach ($this->posted($shop, $key, $id) as $line) {
            $this->add($difference, $line, -$line->paise);
        }

        $difference = array_filter($difference, fn (array $row) => $row['paise'] !== 0);

        if ($difference === []) {
            return;
        }

        $this->write($shop, $key, $id, $document !== null ? $builder->description($document) : "{$key} #{$id} removed", $difference, $accounts, $builder instanceof PostsOnChangeDate);
    }

    /** @return array<int, Line> what's posted so far, as the document asked for it */
    protected function posted(Shop $shop, string $key, int $id): array
    {
        return LedgerLine::query()
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.shop_id', $shop->id)
            ->where('ledger_entries.epoch', $shop->ledger_epoch)
            ->where('ledger_entries.source_type', $key)
            ->where('ledger_entries.source_id', $id)
            ->groupBy('origin_account_code', 'origin_date', 'branch_id', 'party_type', 'party_id')
            ->selectRaw('origin_account_code, origin_date, branch_id, party_type, party_id, SUM(amount_paise) as paise')
            ->toBase()
            ->get()
            ->map(fn ($row) => new Line($row->origin_account_code, (int) $row->paise, substr((string) $row->origin_date, 0, 10), $row->branch_id !== null ? (int) $row->branch_id : null, $row->party_type, $row->party_id !== null ? (int) $row->party_id : null))
            ->all();
    }

    protected function add(array &$difference, Line $line, int $paise): void
    {
        $difference[$line->key()] ??= ['line' => $line, 'paise' => 0];
        $difference[$line->key()]['paise'] += $paise;
    }

    /** A shop's bank and wallet accounts come and go, so each gets its ledger account the first time it's used. */
    protected function bankAccount(Shop $shop, string $code): int
    {
        if (ChartOfAccounts::parentCode($code) !== ChartOfAccounts::BANK || $code === ChartOfAccounts::BANK) {
            throw new RuntimeException("No account {$code}.");
        }

        $shopAccount = ShopAccount::withoutGlobalScopes()->where('shop_id', $shop->id)->find((int) explode('-', $code)[1]);

        return LedgerAccount::withoutGlobalScopes()->firstOrCreate(
            ['shop_id' => $shop->id, 'code' => $code],
            ['name' => $shopAccount?->name ?? 'Bank account', 'type' => LedgerAccount::ASSET, 'shop_account_id' => $shopAccount?->id],
        )->id;
    }

    protected function write(Shop $shop, string $key, int $id, string $description, array $difference, array $accounts, bool $onChangeDate = false): void
    {
        $startDate = $shop->ledger_started_at->toDateString();
        $today = now()->toDateString();
        $entries = [];

        foreach ($difference as $row) {
            /** @var Line $line */
            $line = $row['line'];
            $beforeStart = ! $onChangeDate && $line->date < $startDate;

            // A change to something from before the ledger began can't rewrite what came before it.
            $date = $beforeStart || $onChangeDate ? $today : $line->date;
            $account = $beforeStart && ChartOfAccounts::isProfitAndLoss($line->account) ? ChartOfAccounts::PRIOR_PERIOD : $line->account;

            $entries[$date][] = ['line' => $line, 'account' => $account, 'paise' => $row['paise']];
        }

        foreach ($entries as $date => $lines) {
            if (array_sum(array_column($lines, 'paise')) !== 0) {
                throw new RuntimeException("{$key} #{$id} doesn't balance on {$date}.");
            }

            $entry = LedgerEntry::create([
                'shop_id' => $shop->id,
                'epoch' => $shop->ledger_epoch,
                'entry_date' => $date,
                'source_type' => $key,
                'source_id' => $id,
                'description' => mb_substr($description, 0, 255),
                'created_at' => now(),
            ]);

            foreach ($lines as $item) {
                $accountId = $accounts[$item['account']] ?? $this->bankAccount($shop, $item['account']);

                LedgerLine::create([
                    'shop_id' => $shop->id,
                    'ledger_entry_id' => $entry->id,
                    'ledger_account_id' => $accountId,
                    'amount_paise' => $item['paise'],
                    'party_type' => $item['line']->partyType,
                    'party_id' => $item['line']->partyId,
                    'branch_id' => $item['line']->branchId,
                    'origin_account_code' => $item['line']->account,
                    'origin_date' => $item['line']->date,
                ]);
            }
        }
    }
}
