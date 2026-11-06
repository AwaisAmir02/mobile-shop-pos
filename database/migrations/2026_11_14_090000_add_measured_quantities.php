<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quantities become DECIMAL(12,3) and each line keeps its own unit, so old
 * records still read correctly once a product is archived or deleted. Every
 * existing row is a piece, which is the column default, so nothing is
 * backfilled. Still UNSIGNED: the database itself refuses negative stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('stock_quantity', 12, 3)->unsigned()->default(0)->change();
            $table->string('unit', 10)->default('piece')->after('stock_quantity');
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->unsigned()->change();
            $table->decimal('quantity_remaining', 12, 3)->unsigned()->default(0)->change();
            $table->string('unit', 10)->default('piece')->after('quantity');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->unsigned()->change();
            $table->string('unit', 10)->default('piece')->after('quantity');
        });

        Schema::table('whatsapp_order_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 3)->unsigned()->change();
            $table->string('unit', 10)->default('piece')->after('quantity');
        });

        Schema::table('product_groups', function (Blueprint $table) {
            $table->string('unit', 10)->default('piece')->after('type');
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('measured_units_enabled')->default(false)->after('variants_enabled');
        });
    }

    public function down(): void
    {
        foreach (['products' => 'stock_quantity', 'stock_ins' => 'quantity', 'sale_items' => 'quantity', 'whatsapp_order_items' => 'quantity'] as $table => $column) {
            if (DB::table($table)->whereRaw("{$column} <> FLOOR({$column})")->exists()) {
                throw new RuntimeException("{$table} holds fractional quantities; rolling back would silently round them.");
            }
        }

        if (DB::table('stock_ins')->whereRaw('quantity_remaining <> FLOOR(quantity_remaining)')->exists()) {
            throw new RuntimeException('stock_ins holds fractional remaining quantities; rolling back would silently round them.');
        }

        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('measured_units_enabled'));
        Schema::table('product_groups', fn (Blueprint $table) => $table->dropColumn('unit'));

        Schema::table('whatsapp_order_items', function (Blueprint $table) {
            $table->dropColumn('unit');
            $table->unsignedInteger('quantity')->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('unit');
            $table->unsignedInteger('quantity')->change();
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn('unit');
            $table->unsignedInteger('quantity')->change();
            $table->unsignedInteger('quantity_remaining')->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('unit');
            $table->unsignedInteger('stock_quantity')->default(0)->change();
        });
    }
};
