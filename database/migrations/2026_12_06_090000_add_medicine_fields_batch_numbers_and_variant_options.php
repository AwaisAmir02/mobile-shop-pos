<?php

use App\Services\ProductGroupService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pharmacy and clothing, stage 1.
 *
 * Medicines: a generic (salt) name, searched alongside the brand name and
 * used to offer in-stock substitutes, and an optional DRAP registration
 * number. Stock-ins gain the batch number printed on the pack.
 *
 * Variants: size and colour kept apart from the combined label ("M / Red"),
 * for the Stock In grid and reports. Existing variants are filled in from
 * their labels; a lone value is a colour only when it names one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('variant_size', 50)->nullable()->after('variant_label');
            $table->string('variant_colour', 50)->nullable()->after('variant_size');
            $table->string('generic_name', 150)->nullable()->after('barcode');
            $table->string('drap_reg_no', 30)->nullable()->after('generic_name');

            $table->index(['shop_id', 'generic_name']);
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->string('batch_number', 50)->nullable()->after('expiry_date');
        });

        DB::table('products')->whereNotNull('product_group_id')->whereNotNull('variant_label')->orderBy('id')
            ->select(['id', 'variant_label'])
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)->update(ProductGroupService::optionColumns($row->variant_label));
                }
            });
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn('batch_number');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'generic_name']);
            $table->dropColumn(['variant_size', 'variant_colour', 'generic_name', 'drap_reg_no']);
        });
    }
};
