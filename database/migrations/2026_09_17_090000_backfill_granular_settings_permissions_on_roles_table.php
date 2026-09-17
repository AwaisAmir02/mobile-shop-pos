<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected const GRANULAR_SETTINGS_PERMISSIONS = [
        'settings-categories',
        'settings-networks',
        'settings-bill-config',
        'settings-percentage',
    ];

    /**
     * accessibleTabs() on the Settings screen used to grant Categories,
     * Networks, Bill Providers, and Percentage all together under one
     * coarse 'settings' permission. Now each has its own specific
     * permission, checked independently. Without this backfill, any
     * existing Role that only ever had 'settings' — every Role in every
     * shop using the app today — would silently lose access to those four
     * tabs the moment this ships, even though nothing about the Role
     * itself changed. So: every Role that currently has 'settings' gets
     * the four new values added alongside it, preserving real-world access
     * exactly as it is today. A Role created after this migration is
     * unaffected — it's granted per-tab access exactly as its owner
     * chooses, nothing auto-added.
     */
    public function up(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode($role->permissions, true) ?? [];

            if (! in_array('settings', $permissions, true)) {
                return;
            }

            $merged = array_values(array_unique([...$permissions, ...self::GRANULAR_SETTINGS_PERMISSIONS]));

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($merged),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode($role->permissions, true) ?? [];

            if (! in_array('settings', $permissions, true)) {
                return;
            }

            $stripped = array_values(array_diff($permissions, self::GRANULAR_SETTINGS_PERMISSIONS));

            DB::table('roles')->where('id', $role->id)->update([
                'permissions' => json_encode($stripped),
            ]);
        });
    }
};
