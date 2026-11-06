<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets a plan without these features buy them. Skips a name that already
 * exists, so one created by hand in Admin > Add-ons keeps its own price.
 */
return new class extends Migration
{
    private const ADDONS = [
        'Purchase Orders' => [
            'description' => 'Send purchase orders to vendors and receive goods against them.',
            'feature_keys' => ['purchase_orders'],
            'sort_order' => 2,
        ],
        'Payroll' => [
            'description' => 'Keep staff records and salary advances, run monthly payslips and print them.',
            'feature_keys' => ['payroll'],
            'sort_order' => 3,
        ],
    ];

    public function up(): void
    {
        // A plan with no entry for a feature leaves the shop's switch as it is, so
        // a lapsed add-on or a downgrade would never turn it off. Say so explicitly,
        // as the Plan editor does. Plans with no flags at all don't control features.
        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];

            if ($flags !== []) {
                DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode($flags + ['purchase_orders' => false, 'payroll' => false])]);
            }
        }

        $now = now();

        foreach (self::ADDONS as $name => $addon) {
            if (DB::table('addons')->where('name', $name)->exists()) {
                continue;
            }

            DB::table('addons')->insert([
                'name' => $name,
                'description' => $addon['description'],
                'kind' => 'feature_unlock',
                'limit_key' => null,
                'quantity' => null,
                'feature_keys' => json_encode($addon['feature_keys']),
                'price' => 1000,
                'billing' => 'monthly',
                'is_active' => true,
                'sort_order' => $addon['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** Anything already bought stays in the catalogue, so a purchase never loses its add-on. */
    public function down(): void
    {
        DB::table('addons')
            ->whereIn('name', array_keys(self::ADDONS))
            ->whereNotIn('id', DB::table('shop_addons')->whereNotNull('addon_id')->select('addon_id'))
            ->delete();
    }
};
