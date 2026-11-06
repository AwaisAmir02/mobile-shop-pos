<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional expiry date per batch, and a per-shop switch that makes those
 * dates decide the sell order. No existing row has a date, so nothing is
 * backfilled and every shop keeps selling in FIFO order until it opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->date('expiry_date')->nullable()->after('stock_date');
            $table->index(['shop_id', 'expiry_date'], 'stock_ins_shop_expiry');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedSmallInteger('shelf_life_days')->nullable()->after('unit');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('expiry_tracking_enabled')->default(false)->after('measured_units_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('expiry_tracking_enabled'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('shelf_life_days'));

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropIndex('stock_ins_shop_expiry');
            $table->dropColumn('expiry_date');
        });
    }
};
