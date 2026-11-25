<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale P2 (W6-W11): cheques, sales orders and load sheets, routes and
 * bookers, trade schemes and claims, purchase returns with vendor credit,
 * and contra settlement between a linked customer and vendor.
 *
 * Nothing existing is changed: new tables, and nullable columns with no
 * backfill, so every shop reads exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        // W8: a route is a beat the booker visits, in order.
        Schema::create('delivery_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->json('visit_days')->nullable();
            $table->foreignId('booker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['shop_id', 'name']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->unsignedSmallInteger('route_sequence')->nullable();
            // W11: the same party as one of the shop's vendors.
            $table->foreignId('vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('booker_id')->nullable()->constrained('users')->nullOnDelete();
        });

        // A short label on a payment row an action wrote itself (a bounced cheque, a contra).
        Schema::table('sale_payments', fn (Blueprint $table) => $table->string('note', 120)->nullable());
        Schema::table('stock_in_payments', fn (Blueprint $table) => $table->string('note', 120)->nullable());

        // W9: bonus pieces that came free with a purchase (already inside quantity).
        Schema::table('stock_ins', fn (Blueprint $table) => $table->decimal('free_quantity', 12, 3)->nullable());

        // W6
        Schema::create('cheques', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10); // received | issued
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cheque_number', 40);
            $table->string('bank', 80);
            $table->decimal('amount', 12, 2);
            $table->date('cheque_date');
            $table->date('received_on');
            $table->string('status', 15)->default('pending');
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('deposited_on')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('bounced_on')->nullable();
            $table->string('bounce_reason', 255)->nullable();
            $table->foreignId('customer_receipt_id')->nullable()->constrained()->nullOnDelete();
            $table->json('applied_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status', 'cheque_date']);
        });

        Schema::table('customer_receipts', fn (Blueprint $table) => $table->timestamp('reversed_at')->nullable());

        // W10 + W9 claims + cheque overpayment: what a vendor owes the shop back.
        Schema::create('vendor_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20); // debit_note | claim | cheque
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2);
            $table->date('entry_date');
            // Money the vendor sent back, or credit taken off stock; [{stock_in_id, amount}] and refunds.
            $table->json('applied_to')->nullable();
            $table->string('note', 255)->nullable();
            $table->string('status', 15)->default('active');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'vendor_id']);
        });

        Schema::create('vendor_credit_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_credit_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('refund_date');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // W10
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('reason', 20);
            $table->string('note', 255)->nullable();
            $table->decimal('total_value', 12, 2);
            $table->string('status', 15)->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_in_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3);
            $table->decimal('cost_price', 12, 2);
            $table->decimal('line_value', 12, 2);
            $table->timestamps();
        });

        // W9
        Schema::create('schemes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            // The company that pays for it, when it can be claimed back.
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('buy_quantity');
            $table->unsignedInteger('free_quantity');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('scheme_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->date('claimed_on');
            $table->decimal('claimed_amount', 12, 2);
            $table->string('status', 15)->default('submitted'); // submitted | settled | cancelled
            $table->decimal('settled_amount', 12, 2)->nullable();
            $table->date('settled_on')->nullable();
            $table->string('settlement', 10)->nullable(); // credit | cash
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_credit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('scheme_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scheme_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('free_quantity', 12, 3);
            $table->decimal('cost_value', 12, 2);
            $table->foreignId('scheme_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // W7
        Schema::create('load_sheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->date('load_date');
            $table->string('vehicle', 60)->nullable();
            $table->string('driver', 80)->nullable();
            $table->foreignId('salesman_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->string('status', 15)->default('open'); // open | settled
            $table->timestamp('settled_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('booker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('delivery_routes')->nullOnDelete();
            $table->foreignId('load_sheet_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->date('order_date');
            $table->date('delivery_date')->nullable();
            $table->string('status', 15)->default('booked'); // booked | delivered | cancelled
            $table->decimal('estimated_total', 12, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            // In pieces (or kg…), whatever pack it was ordered in.
            $table->decimal('quantity', 12, 3);
            $table->string('pack_name', 20)->nullable();
            $table->unsignedInteger('pack_size')->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->string('price_tier', 20)->nullable();
            $table->decimal('discount', 12, 2)->default(0);
            $table->timestamps();
        });

        // W11
        Schema::create('contra_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('settled_on');
            $table->json('applied_to');
            $table->string('note', 255)->nullable();
            $table->string('status', 15)->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contra_settlements');
        Schema::dropIfExists('sales_order_lines');
        Schema::dropIfExists('sales_orders');
        Schema::dropIfExists('load_sheets');
        Schema::dropIfExists('scheme_redemptions');
        Schema::dropIfExists('scheme_claims');
        Schema::dropIfExists('schemes');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('vendor_credit_refunds');
        Schema::dropIfExists('vendor_credits');
        Schema::table('customer_receipts', fn (Blueprint $table) => $table->dropColumn('reversed_at'));
        Schema::dropIfExists('cheques');
        Schema::table('stock_ins', fn (Blueprint $table) => $table->dropColumn('free_quantity'));
        Schema::table('stock_in_payments', fn (Blueprint $table) => $table->dropColumn('note'));
        Schema::table('sale_payments', fn (Blueprint $table) => $table->dropColumn('note'));
        Schema::table('sales', fn (Blueprint $table) => $table->dropConstrainedForeignId('booker_id'));
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropConstrainedForeignId('route_id');
            $table->dropColumn('route_sequence');
        });
        Schema::dropIfExists('delivery_routes');
    }
};
