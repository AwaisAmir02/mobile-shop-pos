<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('store_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_order_id', 100);
            $table->string('event_id', 100);
            $table->string('status', 20)->default('pending');
            $table->string('customer_name', 120)->nullable();
            $table->string('customer_phone', 50)->nullable();
            $table->string('delivery_address', 500)->nullable();
            $table->string('delivery_area', 200)->nullable();
            $table->string('payment_method', 20);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('shipping_amount', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('note', 500)->nullable();
            $table->timestamp('placed_at');
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fulfillment_status', 30)->nullable();
            $table->timestamp('fulfillment_updated_at')->nullable();
            $table->timestamp('cancel_flagged_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->unique(['store_connection_id', 'external_order_id']);
            $table->index(['shop_id', 'status', 'placed_at']);
        });

        Schema::create('store_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('external_sku', 100)->nullable();
            $table->string('product_name', 255);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->boolean('removed')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'store_order_id']);
        });

        Schema::table('store_connections', function (Blueprint $table) {
            $table->foreignId('shop_account_id')->nullable()->after('safety_buffer')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_connections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_account_id');
        });

        Schema::dropIfExists('store_order_items');
        Schema::dropIfExists('store_orders');
    }
};
