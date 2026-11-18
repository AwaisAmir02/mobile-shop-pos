<?php

namespace App\Console\Commands;

use App\Services\LegacyImport\LegacyShopImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Read-only. Pulls one shop's rows out of an old install's database (loaded
 * into its own local database first) into a JSON file for legacy:import.
 * Times are read with a +00:00 session, so the file holds UTC instants.
 * Password hashes, sessions and the old shop's subscription invoices are
 * never copied. The file holds customers' names: keep it off shared places.
 */
class LegacyShopExtract extends Command
{
    protected $signature = 'legacy:extract
        {database : The local database the old dump was loaded into}
        {--shop= : The old shop id}
        {--out= : Where to write the JSON file}';

    protected $description = 'Read-only: write one shop from an old install\'s database to a JSON file for legacy:import';

    /** Copied whole (every column), filtered to the shop. */
    public const TABLES = [
        'main_categories', 'accessory_category_options', 'brands', 'vendors', 'customers', 'products',
        'stock_ins', 'sales', 'sale_items', 'sale_payments', 'expenses', 'udhaar_transactions', 'repairs',
        'sale_revisions', 'module_revisions',
    ];

    /** Shop rows here are left behind on purpose; any other table with shop rows stops the extract. */
    public const LEFT_BEHIND = [
        'users' => 'only id, name and email are taken, to map who did what',
        'subscription_invoices' => "the old install's own billing (owner's decision: skip)",
    ];

    public function handle(): int
    {
        $database = (string) $this->argument('database');
        $shopId = (int) $this->option('shop');
        $out = (string) $this->option('out');

        if ($shopId <= 0 || $out === '') {
            $this->error('Pass --shop and --out.');

            return self::FAILURE;
        }

        if (in_array($database, [config('database.connections.mysql.database'), 'mobile_shop_pos', 'mobile_shop_pos_testing'], true)) {
            $this->error("Refusing: {$database} is this app's own database, not a loaded copy of the old one.");

            return self::FAILURE;
        }

        Config::set('database.connections.legacy', array_merge(config('database.connections.mysql'), ['database' => $database, 'timezone' => '+00:00']));
        $db = DB::connection('legacy');

        $shop = $db->table('shops')->where('id', $shopId)->first();

        if (! $shop) {
            $this->error("No shop {$shopId} in {$database}.");

            return self::FAILURE;
        }

        $tablesWithShop = collect($db->select('SELECT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = ?', [$database, 'shop_id']))->pluck('t');
        $unexpected = [];

        foreach ($tablesWithShop as $table) {
            if (in_array($table, self::TABLES, true) || array_key_exists($table, self::LEFT_BEHIND)) {
                continue;
            }

            if (($count = $db->table($table)->where('shop_id', $shopId)->count()) > 0) {
                $unexpected[] = "{$table} ({$count})";
            }
        }

        if ($unexpected !== []) {
            $this->error('This shop has rows the import does not handle: '.implode(', ', $unexpected));

            return self::FAILURE;
        }

        $tables = [];

        foreach (self::TABLES as $table) {
            $tables[$table] = $db->table($table)->where('shop_id', $shopId)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        $payload = [
            'format' => LegacyShopImporter::FORMAT,
            'source' => [
                'database' => $database,
                'shop_id' => $shopId,
                'extracted_at' => now('UTC')->toIso8601String(),
                'times' => 'UTC',
                'left_behind' => self::LEFT_BEHIND + [
                    'subscription_invoices_count' => $db->table('subscription_invoices')->where('shop_id', $shopId)->count(),
                ],
            ],
            'shop' => (array) $db->table('shops')->where('id', $shopId)->first(['id', 'name', 'email', 'phone', 'address', 'disabled_screens']),
            'users' => $db->table('users')->where('shop_id', $shopId)->orderBy('id')->get(['id', 'name', 'email'])->map(fn ($row) => (array) $row)->all(),
            'tables' => $tables,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($out, $json);

        $this->table(['Table', 'Rows'], collect($tables)->map(fn ($rows, $table) => [$table, count($rows)])->values()->all());
        $this->line('Users: '.count($payload['users']).' (id, name, email only)');
        $this->info("Wrote {$out}");
        $this->line('sha256: '.hash('sha256', $json));

        return self::SUCCESS;
    }
}
