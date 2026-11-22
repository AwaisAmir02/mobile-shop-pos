<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packs, stage two.
 *
 * A product with a pack (a strip of 10) can also have an outer pack made of
 * several of them (a box of 3 strips), with its own price. Stock still counts
 * pieces; Stock In and the register can take whole outer packs too.
 *
 * Each sale line now records the pack it was sold in (its name and how many
 * pieces it holds), so the receipt and a later return can say "2 strips"
 * rather than "20". Older lines have none and read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('outer_pack_name', 20)->nullable()->after('pack_price');
            $table->unsignedInteger('outer_pack_size')->nullable()->after('outer_pack_name');
            $table->decimal('outer_pack_price', 12, 2)->nullable()->after('outer_pack_size');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('pack_name', 20)->nullable()->after('unit');
            $table->unsignedInteger('pack_size')->nullable()->after('pack_name');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn(['pack_name', 'pack_size']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['outer_pack_name', 'outer_pack_size', 'outer_pack_price']);
        });
    }
};
