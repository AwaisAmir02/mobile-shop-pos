<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The vendor's bill, matched against what was ordered and received. It holds no
 * balance: what's owed stays on stock_ins, and approval can only correct those
 * same rows. active_marker is 1 until cancelled and NULL after, which is what
 * lets a unique index cover active rows only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number', 100);
            $table->date('invoice_date');
            $table->string('note', 500)->nullable();
            $table->string('status')->default('flagged');
            $table->boolean('auto_passed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'invoice_number', 'active_marker'], 'vendor_invoices_one_active_number');
            $table->index(['shop_id', 'status']);
        });

        Schema::create('vendor_invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('grn_line_id')->constrained('grn_lines')->restrictOnDelete();
            $table->foreignId('stock_in_id')->constrained('stock_ins')->restrictOnDelete();
            $table->decimal('billed_quantity', 12, 3);
            $table->decimal('billed_amount', 10, 2);
            $table->boolean('amended')->default(false);
            $table->decimal('previous_cost_price', 10, 2)->nullable();
            $table->decimal('previous_total_cost', 10, 2)->nullable();
            $table->decimal('unsold_quantity_at_approval', 12, 3)->nullable();
            $table->unsignedTinyInteger('active_marker')->nullable()->default(1);

            $table->unique(['grn_line_id', 'active_marker'], 'vendor_invoice_lines_one_active_per_grn_line');
        });

        Schema::create('vendor_invoice_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vendor_invoice_id')->constrained()->cascadeOnDelete();
            $table->string('label', 100);
            $table->decimal('amount', 10, 2);
        });

        Schema::table('stock_in_payments', function (Blueprint $table) {
            $table->foreignId('vendor_invoice_id')->nullable()->after('stock_in_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_in_payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('vendor_invoice_id'));
        Schema::dropIfExists('vendor_invoice_charges');
        Schema::dropIfExists('vendor_invoice_lines');
        Schema::dropIfExists('vendor_invoices');
    }
};
