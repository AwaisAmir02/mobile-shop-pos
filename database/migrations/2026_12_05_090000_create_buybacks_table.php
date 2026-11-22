<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Used phones bought from customers (Mobile & Electronics shops). Each one
 * is a Mobile product plus a vendor-less, fully paid stock-in batch, which
 * carries the cost, FIFO, profit and the money out (stock_in_payments, so
 * Cash & Balances sees it). This row keeps who sold it and in what state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('buybacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_in_id')->constrained('stock_ins')->cascadeOnDelete();
            // A trade-in: the sale it part-paid and its Trade-in payment row. Undone only by cancelling that sale.
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_payment_id')->nullable()->constrained('sale_payments')->nullOnDelete();

            $table->string('seller_name');
            $table->string('seller_phone', 30)->nullable();
            $table->string('seller_cnic', 15)->nullable();

            $table->string('brand');
            $table->string('model')->nullable();
            $table->string('imei', 20)->nullable();
            $table->string('condition', 20);
            $table->unsignedTinyInteger('battery_health')->nullable();
            $table->boolean('pta_approved')->nullable();
            $table->boolean('has_box')->default(false);
            $table->boolean('has_charger')->default(false);

            $table->decimal('price', 12, 2);
            $table->decimal('selling_price', 12, 2);
            $table->date('bought_on');
            $table->text('note')->nullable();

            // A phone this shop sold before and is buying back reuses its old product; this restores it on cancel.
            $table->boolean('reused_product')->default(false);
            $table->json('product_before')->nullable();

            $table->string('status', 20)->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'bought_on']);
            $table->index(['shop_id', 'imei']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('buybacks');
    }
};
