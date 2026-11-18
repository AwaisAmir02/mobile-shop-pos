<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\LegacyImport\LegacyShopAudit;
use Illuminate\Console\Command;

/**
 * Read-only. Checks a shop's stock, vendor payments and customer dues with
 * the app's own code against plain SQL, and with --against compares its
 * totals with the legacy:extract file it was imported from.
 */
class LegacyShopCheck extends Command
{
    protected $signature = 'legacy:check {--shop= : The shop to check} {--against= : A legacy:extract file to compare totals with}
        {--ignore=* : table:id of a row that was in the shop before the import (legacy:import prints these)}';

    protected $description = 'Read-only: check an imported shop\'s stock, payments and dues, and compare totals with the old data';

    public function handle(): int
    {
        $shop = Shop::find((int) $this->option('shop'));

        if (! $shop) {
            $this->error('Pass --shop with an existing shop id.');

            return self::FAILURE;
        }

        $this->line('Connected to: '.config('database.connections.'.config('database.default').'.database')." - shop {$shop->id} ({$shop->name})");

        $audit = new LegacyShopAudit($shop->id);
        $exclude = [];

        foreach ((array) $this->option('ignore') as $item) {
            [$table, $id] = array_pad(explode(':', (string) $item, 2), 2, null);

            if (! in_array($table, [...LegacyShopAudit::TABLES, 'stock_in_payments'], true) || ! ctype_digit((string) $id)) {
                $this->error("--ignore must look like customers:4, not {$item}.");

                return self::FAILURE;
            }

            $exclude[$table][] = (int) $id;
        }

        $after = LegacyShopAudit::totals($audit->tables($exclude));
        $passed = true;

        if ($file = $this->option('against')) {
            $before = LegacyShopAudit::totals(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['tables']);
            $rows = collect($before)->map(fn ($value, $label) => [$label, $value, $after[$label] ?? '-', ($after[$label] ?? null) === $value ? 'same' : 'DIFFERENT'])->values()
                ->merge(collect($after)->diffKeys($before)->map(fn ($value, $label) => [$label, '-', $value, 'only after']))->all();
            $passed = collect($before)->every(fn ($value, $label) => ($after[$label] ?? null) === $value);
            $this->table(['', 'Old shop', $exclude === [] ? 'This shop' : 'This shop, less ignored rows', ''], $rows);

            if ($exclude !== []) {
                $this->info('Whole shop, ignored rows included');
                $this->table(['', 'Whole shop'], collect(LegacyShopAudit::totals($audit->tables()))->map(fn ($value, $label) => [$label, $value])->values()->all());
            }
        } else {
            $this->table(['', 'This shop'], collect($after)->map(fn ($value, $label) => [$label, $value])->values()->all());
        }

        $checks = $audit->checks();
        $this->table(['Check', 'Result', 'Detail'], collect($checks)->map(fn ($c) => [$c[0], $c[1] ? 'pass' : 'FAIL', $c[2]])->all());

        return $passed && collect($checks)->every(fn ($c) => $c[1]) ? self::SUCCESS : self::FAILURE;
    }
}
