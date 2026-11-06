<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock removed for spoilage, damage, theft or a count correction. A write-off
 * holds one line per batch it drew from, like a sale's items, so cancelling it
 * can put each quantity back in the exact batch it came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->string('unit', 10)->default('piece');
            $table->string('reason');
            $table->string('note', 500)->nullable();
            $table->date('written_off_on');
            $table->decimal('total_cost', 12, 2)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'written_off_on']);
        });

        Schema::create('stock_write_off_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_write_off_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_in_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->decimal('cost_price', 10, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_write_off_items');
        Schema::dropIfExists('stock_write_offs');
    }
};
