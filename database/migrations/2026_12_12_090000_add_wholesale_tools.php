<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wholesale tools (W1-W5): business details, price level, credit limit and
 * payment terms on customers; a trade price on products; a due date and any
 * credit-limit override on sales; the price level a line was sold at; and the
 * counter receipts a customer's lump-sum payments are recorded under.
 *
 * Every column is nullable with no backfill, so existing shops read exactly
 * as before: a null is "not set".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('business_name')->nullable()->after('name');
            $table->string('contact_person', 120)->nullable()->after('business_name');
            $table->string('phone_2', 50)->nullable()->after('phone');
            $table->string('area', 100)->nullable()->after('address');
            $table->string('ntn', 30)->nullable();
            $table->string('strn', 30)->nullable();
            $table->string('cnic', 20)->nullable();
            // null = the shop's default (the trade price, in a wholesale business); 'normal' = the normal selling price.
            $table->string('price_tier', 20)->nullable();
            // null = no limit.
            $table->decimal('credit_limit', 12, 2)->nullable();
            // Days a credit sale has before it falls due; null = no terms.
            $table->unsignedSmallInteger('payment_terms_days')->nullable();

            $table->index(['shop_id', 'area']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('wholesale_price', 10, 2)->nullable()->after('cost_price');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('total');
            $table->foreignId('credit_override_by')->nullable()->after('due_date')->constrained('users')->nullOnDelete();
            $table->string('credit_override_reason', 255)->nullable()->after('credit_override_by');
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('price_tier', 20)->nullable();
        });

        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            // Where each rupee went: [{source, id, reference, amount}], oldest first, then any advance.
            $table->json('applied_to');
            $table->date('receipt_date');
            $table->timestamps();

            $table->index(['shop_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_receipts');

        Schema::table('sale_items', fn (Blueprint $table) => $table->dropColumn('price_tier'));

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credit_override_by');
            $table->dropColumn(['due_date', 'credit_override_reason']);
        });

        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('wholesale_price'));

        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'area']);
            $table->dropColumn(['business_name', 'contact_person', 'phone_2', 'area', 'ntn', 'strn', 'cnic', 'price_tier', 'credit_limit', 'payment_terms_days']);
        });
    }
};
