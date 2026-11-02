<?php

use App\Support\WhatsAppSplitBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits the "whatsapp" feature into ordering and messaging. The old
 * shops.whatsapp_enabled and addon feature_key columns are kept (no longer
 * read) so the previous release can still run; a later migration drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('whatsapp_ordering_enabled')->default(false)->after('whatsapp_enabled');
            $table->boolean('whatsapp_messaging_enabled')->default(false)->after('whatsapp_ordering_enabled');
        });

        foreach (WhatsAppSplitBackfill::ADDON_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->json('feature_keys')->nullable()->after('feature_key');
            });
        }

        DB::statement(WhatsAppSplitBackfill::SHOP_COLUMNS_SQL);
        DB::statement(WhatsAppSplitBackfill::PLAN_FLAGS_SQL);
        DB::statement(WhatsAppSplitBackfill::SHOP_OVERRIDES_SQL);

        foreach (WhatsAppSplitBackfill::ADDON_TABLES as $name) {
            DB::statement(WhatsAppSplitBackfill::addonKeysSql($name));
        }
    }

    public function down(): void
    {
        DB::statement(WhatsAppSplitBackfill::reverseFlagsSql('plans', 'feature_flags'));
        DB::statement(WhatsAppSplitBackfill::reverseFlagsSql('shops', 'feature_overrides'));
        DB::statement(WhatsAppSplitBackfill::REVERSE_SHOP_COLUMNS_SQL);

        foreach (WhatsAppSplitBackfill::ADDON_TABLES as $name) {
            foreach (WhatsAppSplitBackfill::reverseAddonKeysSql($name) as $sql) {
                DB::statement($sql);
            }

            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('feature_keys');
            });
        }

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_ordering_enabled', 'whatsapp_messaging_enabled']);
        });
    }
};
