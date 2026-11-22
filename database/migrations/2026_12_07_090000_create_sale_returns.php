<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sale returns: a credit note against an earlier sale (RN-000123).
 *
 * Each return line points at the exact sale_items row it takes back, and so
 * at the batch that row was sold from: the stock goes back there at the cost
 * it left with. What the goods are worth first cancels what the customer still
 * owes on the sale (due_reduction); only the rest is refunded. The sale's own
 * total and payments are never changed.
 *
 * refund_credit / refund_exchange and exchange_sale_id are for store credit
 * and exchanges, which come in later stages; they stay 0 / null until then.
 *
 * Shops gain their return policy: how many days after a sale a return is
 * accepted (0 = no limit) and whether money can be handed back at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('return_date');
            $table->decimal('value_total', 12, 2);
            $table->decimal('due_reduction', 12, 2)->default(0);
            $table->decimal('refund_cash', 12, 2)->default(0);
            $table->string('refund_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('refund_credit', 12, 2)->default(0);
            $table->decimal('refund_exchange', 12, 2)->default(0);
            $table->foreignId('exchange_sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->string('reason', 255)->nullable();
            $table->string('late_reason', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'return_date']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            // Restrict: a sale's lines can't be rewritten while a return points at them.
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_in_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->string('serial_number', 50)->nullable();
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->string('unit', 10)->default('piece');
            $table->decimal('cost_price', 12, 2)->nullable();
            $table->decimal('value', 12, 2);
            $table->string('disposition', 10)->default('restock');
            $table->boolean('restocked')->default(true);
            $table->foreignId('stock_write_off_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedSmallInteger('return_window_days')->default(30);
            $table->boolean('cash_refunds_allowed')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['return_window_days', 'cash_refunds_allowed']);
        });

        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
