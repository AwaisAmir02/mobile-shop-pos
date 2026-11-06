<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which account money moved through, where the app didn't record it. All optional
 * and history only: nothing that totals money reads them except the ledger, and
 * money with none stays in Receipts Not Classified. Plus the owner's withdrawals
 * and paying over withheld salary, which had nowhere to go.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['expenses', 'stock_in_payments', 'udhaar_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('payment_method', 20)->nullable();
                $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('employee_advance_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::create('counter_service_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('paid_on');
            $table->string('payment_method', 20)->nullable();
            $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });

        foreach (['owner_withdrawals', 'withholding_payments'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('amount', 10, 2);
                $table->date('paid_on');
                $table->string('payment_method', 20)->nullable();
                $table->foreignId('shop_account_id')->nullable()->constrained()->nullOnDelete();
                $table->string('note', 255)->nullable();
                $table->string('status')->default('active');
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancel_reason')->nullable();
                $table->timestamps();

                $table->index(['shop_id', 'paid_on']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_payments');
        Schema::dropIfExists('owner_withdrawals');
        Schema::dropIfExists('counter_service_payments');
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('employee_advance_id'));

        foreach (['expenses', 'stock_in_payments', 'udhaar_transactions'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('shop_account_id');
                $table->dropColumn('payment_method');
            });
        }
    }
};
