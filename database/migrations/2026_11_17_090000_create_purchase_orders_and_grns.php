<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase Orders and Goods Receipt Notes: a document layer above Stock In.
 * A PO moves no stock and no money. Each GRN line creates one stock_ins row,
 * and that row stays the only source of FIFO/FEFO order, cost and vendor dues.
 * Received quantities are never stored on the PO; they're summed from GRN lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('status')->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'vendor_id']);
        });

        Schema::create('po_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->string('unit', 10)->default('piece');
            $table->decimal('expected_cost', 10, 2)->nullable();

            $table->index('product_id');
        });

        Schema::create('grns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('received_on');
            $table->string('note', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'received_on']);
        });

        Schema::create('grn_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grn_id')->constrained()->cascadeOnDelete();
            $table->foreignId('po_line_id')->nullable()->constrained('po_lines')->nullOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name');
            $table->decimal('quantity', 12, 3)->unsigned();
            $table->string('unit', 10)->default('piece');
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('selling_price', 10, 2);
            $table->date('expiry_date')->nullable();
        });

        Schema::table('stock_ins', function (Blueprint $table) {
            $table->foreignId('grn_line_id')->nullable()->after('vendor_id')->constrained('grn_lines')->nullOnDelete();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('purchase_orders_enabled')->default(false)->after('expiry_tracking_enabled');
        });

        // Included in the Commerce plan, and on for the shops already on it.
        foreach (DB::table('plans')->where('name', 'Commerce')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => json_encode(['purchase_orders' => true] + $flags)]);
            DB::table('shops')->where('plan_id', $plan->id)->update(['purchase_orders_enabled' => true]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->whereNotNull('feature_flags')->get(['id', 'feature_flags']) as $plan) {
            $flags = json_decode((string) $plan->feature_flags, true) ?: [];
            unset($flags['purchase_orders']);
            DB::table('plans')->where('id', $plan->id)->update(['feature_flags' => $flags === [] ? null : json_encode($flags)]);
        }

        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('purchase_orders_enabled'));
        Schema::table('stock_ins', fn (Blueprint $table) => $table->dropConstrainedForeignId('grn_line_id'));
        Schema::dropIfExists('grn_lines');
        Schema::dropIfExists('grns');
        Schema::dropIfExists('po_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
