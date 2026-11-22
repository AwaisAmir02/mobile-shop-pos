<?php

namespace App\Actions;

use App\Models\BackupRun;
use App\Models\Shop;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Permanently removes a shop and everything it owns. Super Admin only, from
 * the admin shop page.
 *
 * The tables are read from the database itself (every table with a shop_id
 * column), so a table added later is covered without touching this class.
 * Rows go children first, following the foreign keys between those tables;
 * a delete that a RESTRICT key still blocks (one reached only through a
 * table with no shop_id of its own) is retried after the others, and
 * anything still blocked rolls the whole delete back. Rows in tables with
 * no shop_id (sale lines, batch links...) go with their parents by cascade.
 *
 * Users are deleted explicitly: users.shop_id is SET NULL, so a plain
 * delete of the shop would leave its logins behind with no shop.
 *
 * Refused unless a backup has succeeded recently, since nothing else can
 * bring the shop back.
 */
class DeleteShop
{
    /**
     * @return array{tables: array<string, int>, files: int}
     */
    public static function handle(Shop $shop, string $typedName, User $admin): array
    {
        if (! $admin->is_super_admin) {
            throw new RuntimeException('Only a Super Admin can delete a shop.');
        }

        if (trim($typedName) !== trim($shop->name)) {
            throw new RuntimeException('The name typed doesn’t match this shop’s name, so nothing was deleted.');
        }

        if (BackupRun::isStale()) {
            throw new RuntimeException('No backup has succeeded in the last '.config('backup.stale_after_hours').' hours, so nothing was deleted. A deleted shop can only come back from a backup: fix backups first (Backups page), then try again.');
        }

        $shopId = $shop->id;
        $name = $shop->name;
        $users = User::where('shop_id', $shopId)->get(['id', 'email', 'avatar_path']);
        $files = self::filesFor($shop, $users->pluck('avatar_path')->filter()->all());

        $counts = DB::transaction(function () use ($shopId, $users) {
            Shop::whereKey($shopId)->lockForUpdate()->firstOrFail();

            $tables = self::shopTables();
            $counts = [];

            foreach ($tables as $table) {
                $counts[$table] = DB::table($table)->where('shop_id', $shopId)->count();
            }

            self::deleteRows(self::ordered($tables), $shopId);

            // Logged-in sessions and reset links of the shop's users, which no key ties to them.
            DB::table('sessions')->whereIn('user_id', $users->pluck('id'))->delete();
            DB::table('password_reset_tokens')->whereIn('email', $users->pluck('email')->filter())->delete();

            DB::table('shops')->where('id', $shopId)->delete();

            return array_filter($counts);
        });

        app(Entitlements::class)->forgetMeters($shop);
        $removed = self::deleteFiles($files);

        Log::warning('Shop permanently deleted', [
            'shop_id' => $shopId,
            'shop_name' => $name,
            'deleted_by' => $admin->id,
            'rows' => $counts,
            'files' => $removed,
        ]);

        return ['tables' => $counts, 'files' => $removed];
    }

    /** @return array<int, string> every table with a shop_id column, other than shops itself */
    public static function shopTables(): array
    {
        return collect(DB::select(
            'SELECT TABLE_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ? ORDER BY TABLE_NAME',
            ['shop_id'],
        ))->pluck('name')->reject(fn (string $table) => $table === 'shops')->values()->all();
    }

    /**
     * Children before the tables they point at, among $tables. A cycle (or
     * a table pointing at itself) is left to the retry in deleteRows().
     *
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    public static function ordered(array $tables): array
    {
        $parents = array_fill_keys($tables, []);

        foreach (DB::select(
            'SELECT TABLE_NAME AS child, REFERENCED_TABLE_NAME AS parent FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL',
        ) as $key) {
            if (isset($parents[$key->child], $parents[$key->parent]) && $key->child !== $key->parent) {
                $parents[$key->child][$key->parent] = true;
            }
        }

        $order = [];
        $done = [];
        $visiting = [];

        // Depth-first over "who points at me": a table goes out once every table pointing at it has.
        $children = array_fill_keys($tables, []);
        foreach ($parents as $child => $ofChild) {
            foreach (array_keys($ofChild) as $parent) {
                $children[$parent][] = $child;
            }
        }

        $visit = function (string $table) use (&$visit, &$order, &$done, &$visiting, $children) {
            if (isset($done[$table]) || isset($visiting[$table])) {
                return;
            }

            $visiting[$table] = true;

            foreach ($children[$table] as $child) {
                $visit($child);
            }

            unset($visiting[$table]);
            $done[$table] = true;
            $order[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $order;
    }

    /** @param array<int, string> $tables */
    protected static function deleteRows(array $tables, int $shopId): void
    {
        $remaining = $tables;

        while ($remaining !== []) {
            $blocked = [];
            $lastError = null;

            foreach ($remaining as $table) {
                try {
                    DB::table($table)->where('shop_id', $shopId)->delete();
                } catch (QueryException $e) {
                    // 1451: a row elsewhere still points at one of these. Only that statement is undone.
                    if (($e->errorInfo[1] ?? null) !== 1451) {
                        throw $e;
                    }

                    $blocked[] = $table;
                    $lastError = $e;
                }
            }

            if (count($blocked) === count($remaining)) {
                throw new RuntimeException('The shop could not be deleted because other records still point at its data ('.implode(', ', $blocked).'). Nothing was deleted.', 0, $lastError);
            }

            $remaining = $blocked;
        }
    }

    /**
     * @param  array<int, string>  $avatars
     * @return array<int, array{disk: string, path: string, dir: bool}>
     */
    protected static function filesFor(Shop $shop, array $avatars): array
    {
        $id = $shop->id;
        $files = [
            ['disk' => 'public', 'path' => 'shop-'.$id, 'dir' => true],
            ['disk' => 'local', 'path' => 'support/'.$id, 'dir' => true],
            ['disk' => 'local', 'path' => 'payment-proofs/'.$id, 'dir' => true],
            ['disk' => 'local', 'path' => 'whatsapp-slips/'.$id, 'dir' => true],
            ['disk' => 'local', 'path' => 'product-imports/'.$id, 'dir' => true],
            ['disk' => 'local', 'path' => 'prescriptions/'.$id, 'dir' => true],
        ];

        // Anything stored outside the shop's own folder: user photos, and an
        // old logo or product image saved before per-shop folders.
        $paths = array_merge(
            $avatars,
            array_filter([$shop->logo_path]),
            DB::table('products')->where('shop_id', $id)->whereNotNull('image_path')->pluck('image_path')->all(),
        );

        foreach (array_unique($paths) as $path) {
            if (! str_starts_with($path, 'shop-'.$id.'/')) {
                $files[] = ['disk' => 'public', 'path' => $path, 'dir' => false];
            }
        }

        return $files;
    }

    /** Files go after the rows are gone for good; a failure here only leaves orphan files. */
    protected static function deleteFiles(array $files): int
    {
        $removed = 0;

        foreach ($files as $file) {
            try {
                $disk = Storage::disk($file['disk']);

                if ($file['dir'] && $disk->directoryExists($file['path'])) {
                    $removed += count($disk->allFiles($file['path']));
                    $disk->deleteDirectory($file['path']);
                } elseif (! $file['dir'] && $disk->exists($file['path'])) {
                    $disk->delete($file['path']);
                    $removed++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $removed;
    }
}
