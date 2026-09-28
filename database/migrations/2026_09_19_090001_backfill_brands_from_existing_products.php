<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Brand used to be free text typed straight into a mobile product's
     * `details->brand`. Now it's picked from a managed per-shop list, so
     * every distinct brand string already in use gets its own Brand row —
     * existing products keep their `details->brand` string exactly as-is
     * (nothing about the product row changes), this only seeds the new
     * dropdown/Settings list so historical brands are selectable and
     * visible going forward, and validate() against them keeps working for
     * anyone who re-saves an existing product without touching the brand.
     */
    public function up(): void
    {
        $seen = [];

        DB::table('products')
            ->where('type', 'mobile')
            ->orderBy('id')
            ->select('shop_id', 'details')
            ->get()
            ->each(function ($product) use (&$seen) {
                $brand = trim(json_decode($product->details ?? '{}', true)['brand'] ?? '');

                if ($brand === '') {
                    return;
                }

                $key = $product->shop_id.'|'.$brand;

                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;

                DB::table('brands')->insertOrIgnore([
                    'shop_id' => $product->shop_id,
                    'name' => $brand,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    /**
     * Removes exactly the (shop_id, name) pairs this migration would have
     * inserted, re-derived the same way — not a blanket wipe, so a Brand a
     * shop created by hand after this ran (even one that happens to share a
     * name) is only removed if it also happens to match an existing
     * product's brand string, mirroring the same trade-off the granular
     * settings-permissions backfill accepts for its own down().
     */
    public function down(): void
    {
        $seen = [];

        DB::table('products')
            ->where('type', 'mobile')
            ->orderBy('id')
            ->select('shop_id', 'details')
            ->get()
            ->each(function ($product) use (&$seen) {
                $brand = trim(json_decode($product->details ?? '{}', true)['brand'] ?? '');

                if ($brand === '') {
                    return;
                }

                $key = $product->shop_id.'|'.$brand;

                if (isset($seen[$key])) {
                    return;
                }

                $seen[$key] = true;

                DB::table('brands')->where('shop_id', $product->shop_id)->where('name', $brand)->delete();
            });
    }
};
