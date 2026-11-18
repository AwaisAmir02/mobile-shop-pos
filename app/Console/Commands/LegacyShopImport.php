<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\Shop;
use App\Services\LegacyImport\LegacyImportException;
use App\Services\LegacyImport\LegacyShopAudit;
use App\Services\LegacyImport\LegacyShopImporter;
use App\Services\LowStockAlerts;
use App\Support\ApiShopContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports a legacy:extract file into one empty shop, in one transaction.
 * Without --commit (the default) it runs everything, prints the report and
 * rolls back. With --commit it keeps the result only if every check passes.
 * --database must name the database this app is connected to, so the
 * command can't quietly run against the wrong one.
 */
class LegacyShopImport extends Command
{
    protected $signature = 'legacy:import
        {file : The JSON file from legacy:extract}
        {--shop= : The shop to import into}
        {--email= : That shop\'s email, as a second check it is the right shop}
        {--database= : The database name this app is connected to, as a check}
        {--sha256= : The file\'s checksum from legacy:extract}
        {--commit : Keep the result (only if every check passes); otherwise it is rolled back}';

    protected $description = 'Import one shop from an old install into an empty shop (rolled back unless --commit)';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = config("database.connections.{$connection}.database");
        $host = config("database.connections.{$connection}.host");

        $this->line("Connected to: {$database} on {$host}");

        if ($this->option('database') !== $database) {
            $this->error("Refusing: --database is '{$this->option('database')}' but this app is connected to '{$database}'.");

            return self::FAILURE;
        }

        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("No file at {$file}.");

            return self::FAILURE;
        }

        $sha = hash_file('sha256', $file);
        $this->line("File: {$file} (sha256 {$sha})");

        if ($this->option('sha256') && ! hash_equals(strtolower((string) $this->option('sha256')), $sha)) {
            $this->error('Refusing: the file\'s checksum does not match --sha256.');

            return self::FAILURE;
        }

        $shop = Shop::find((int) $this->option('shop'));

        if (! $shop || ! $this->option('email')) {
            $this->error('Pass --shop (an existing shop) and --email.');

            return self::FAILURE;
        }

        try {
            $importer = LegacyShopImporter::fromFile($file, $shop);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($blockers = $importer->blockers((string) $this->option('email'))) {
            foreach ($blockers as $blocker) {
                $this->error($blocker);
            }

            return self::FAILURE;
        }

        // No low-stock emails for history being replayed, and no other mail either.
        $this->laravel->instance(LowStockAlerts::class, new class extends LowStockAlerts
        {
            public function check(Product $product): void {}
        });
        config(['mail.default' => 'log']);

        $audit = new LegacyShopAudit($shop->id);
        $booksBefore = $audit->bookCounts();
        // Customers and repairs the owner already entered stay; the totals compare only what the import adds.
        $existingRows = $audit->tables();
        $existingIds = LegacyShopAudit::idsOf($existingRows);
        $data = json_decode(file_get_contents($file), true);

        DB::beginTransaction();

        try {
            $report = ApiShopContext::current()->run($shop->id, fn () => $importer->run());
            $before = LegacyShopAudit::totals($data['tables']);
            $after = LegacyShopAudit::totals($audit->tables($existingIds));
            $wholeShop = LegacyShopAudit::totals($audit->tables());
            $checks = [...$audit->checks($booksBefore), $audit->existingRowsUnchanged($existingRows)];
        } catch (LegacyImportException $e) {
            DB::rollBack();
            $this->error('Stopped, nothing kept: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            DB::rollBack();
            $this->error('Stopped, nothing kept: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printReport($report, $before, $after, $checks, $wholeShop, $existingIds);

        $passed = collect($checks)->every(fn ($check) => $check[1]) && collect($before)->every(fn ($value, $label) => ($after[$label] ?? null) === $value);

        if ($this->option('commit') && $passed) {
            DB::commit();
            $this->info("Committed: shop {$shop->id} now holds the imported data.");
            $ignore = collect($existingIds)->flatMap(fn ($ids, $table) => array_map(fn ($id) => " --ignore={$table}:{$id}", $ids))->implode('');
            $this->line("Check it with: php artisan legacy:check --shop={$shop->id} --against={$file}{$ignore}");

            return self::SUCCESS;
        }

        DB::rollBack();

        if (! $passed) {
            $this->error('Rolled back: a check or a total did not match (see above).');

            return self::FAILURE;
        }

        $this->warn('Dry run: everything above was rolled back. Add --commit to keep it.');

        return self::SUCCESS;
    }

    protected function printReport(array $report, array $before, array $after, array $checks, array $wholeShop, array $existingIds): void
    {
        if ($existingIds !== []) {
            $this->warn('Already in the shop before the import, left as they are and not counted below: '
                .collect($existingIds)->map(fn ($ids, $table) => $table.' #'.implode(', #', $ids))->implode('; '));
        }

        $this->newLine();
        $this->info('Rows created');
        $this->table(['Table', 'Rows'], collect($report['created'])->map(fn ($n, $t) => [$t, $n])->values()->all());

        foreach ($report['reused'] as $table => $names) {
            $this->line("Already in the shop, reused for {$table}: ".implode(', ', $names));
        }

        $this->newLine();
        $this->info('Categories');
        $this->table(['Old', 'Now', 'Why'], collect($report['categories'])->map(fn ($c) => [$c['old'], $c['new'].($c['existing'] ? ' (already there)' : ''), $c['why']])->all());

        $this->newLine();
        $this->info('Modules switched off');
        $this->line('Before: '.json_encode($report['modules']['before']));
        $this->line('After:  '.json_encode($report['modules']['after']));

        if ($report['modules']['hidden_on_old_but_left_on'] !== []) {
            $this->warn('Off on the old shop but left on here (not in the agreed list): '.implode(', ', $report['modules']['hidden_on_old_but_left_on']));
        }

        $this->newLine();
        $this->info('Totals: old shop (file) vs what the import added; the last column is the whole shop afterwards');
        $this->table(['', 'Before (file)', 'Added by import', '', 'Whole shop after'], collect($before)->map(fn ($value, $label) => [$label, $value, $after[$label] ?? '-', ($after[$label] ?? null) === $value ? 'same' : 'DIFFERENT', $wholeShop[$label] ?? '-'])->values()
            ->merge(collect($after)->diffKeys($before)->map(fn ($value, $label) => [$label, '-', $value, 'only after', $wholeShop[$label] ?? '-']))
            ->merge(collect($wholeShop)->diffKeys($before)->diffKeys($after)->map(fn ($value, $label) => [$label, '-', '-', 'already there', $value]))->all());

        $this->newLine();
        $this->info('Checks (the app\'s own code vs plain SQL)');
        $this->table(['Check', 'Result', 'Detail'], collect($checks)->map(fn ($c) => [$c[0], $c[1] ? 'pass' : 'FAIL', $c[2]])->all());

        foreach ($report['notes'] as $note) {
            $this->warn($note);
        }
    }
}
