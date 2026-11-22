<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store credit: a customer's own credit ledger, kept apart from udhaar.
 *
 * Each row is one event. An issue (from a return) adds credit and keeps what's
 * left of it in `remaining`, like a stock batch. A spend (paying for a sale), a
 * cash-out (credit handed over as money) or an expiry takes credit away, and
 * store_credit_draws say which issues it came out of, soonest-expiring first.
 * The balance is the sum of the active rows.
 *
 * Shops gain how many days issued credit lasts (null = never expires).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_credit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->decimal('remaining', 12, 2)->default(0);
            $table->date('expires_on')->nullable();
            $table->date('entry_date');
            $table->foreignId('sale_return_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'customer_id', 'status']);
            $table->index(['shop_id', 'type', 'expires_on']);
        });

        Schema::create('store_credit_draws', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('store_credit_entries')->cascadeOnDelete();
            $table->foreignId('issue_id')->constrained('store_credit_entries')->restrictOnDelete();
            $table->decimal('amount', 12, 2);
            $table->timestamps();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->unsignedSmallInteger('store_credit_expiry_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('store_credit_expiry_days');
        });

        Schema::dropIfExists('store_credit_draws');
        Schema::dropIfExists('store_credit_entries');
    }
};
