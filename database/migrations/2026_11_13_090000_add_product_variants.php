<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variant is an ordinary products row, so its stock, barcode, price and FIFO
 * batches work exactly as they do for any product. A product with no group is
 * simply a product: nothing is backfilled and no existing row changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('type');
            $table->json('details')->nullable();
            $table->string('image_path')->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_group_id')->nullable()->after('shop_id')->constrained('product_groups')->nullOnDelete();
            $table->string('variant_label', 100)->nullable()->after('name');

            $table->unique(['product_group_id', 'variant_label']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('variants_enabled')->default(false)->after('services_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('variants_enabled');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['product_group_id']);
            $table->dropUnique(['product_group_id', 'variant_label']);
            $table->dropColumn(['product_group_id', 'variant_label']);
        });

        Schema::dropIfExists('product_groups');
    }
};
