<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promotions, v1: a percentage or a flat amount off, between two dates, on
 * chosen products, variant groups, categories or everything. The register
 * applies the best one to each line by itself; the cashier can't remove it.
 * WhatsApp and online orders don't get them.
 *
 * Each sale line records the promotion it got (id, name as it was, and how
 * much of its discount_amount came from it). discount_amount still holds the
 * whole line discount, so totals, reports and the ledger read as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            // 'percent' (value is 1-100) or 'flat' (value is rupees off each piece or unit).
            $table->string('kind', 10);
            $table->decimal('value', 12, 2);
            // 'all', 'products', 'groups' or 'categories'; targets holds the ids or category slugs.
            $table->string('applies_to', 12)->default('all');
            $table->json('targets')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'is_active', 'starts_on']);
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('promotion_id')->nullable()->after('discount_amount')->constrained()->nullOnDelete();
            $table->string('promotion_name', 60)->nullable()->after('promotion_id');
            $table->decimal('promotion_discount', 12, 2)->default(0)->after('promotion_name');
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('promotion_id');
            $table->dropColumn(['promotion_name', 'promotion_discount']);
        });

        Schema::dropIfExists('promotions');
    }
};
