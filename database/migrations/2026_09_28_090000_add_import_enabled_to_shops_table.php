<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A separate opt-IN column, deliberately not a ShopScreen/disabled_screens
     * entry — that mechanism is opt-OUT (empty = everything enabled), and
     * reusing it here would mean any future shop-creation path that forgets
     * to explicitly disable this one key gets it on by default. DEFAULT 0
     * means both existing and newly created shops are correct with no
     * backfill step required.
     */
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('import_enabled')->default(false)->after('disabled_screens');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('import_enabled');
        });
    }
};
