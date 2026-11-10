<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cash & Balances. Account balances are worked out from the records that
 * already move money (sales, udhaar, counter services, vendor payments,
 * expenses, payroll...). This table only holds what nothing else records:
 * - opening: the balance of cash or an account on the day tracking starts;
 * - transfer: money moved between cash and accounts (bank to JazzCash...);
 * - count: a counted balance; the difference from the expected balance is
 *   kept as a correction so the running balance matches the real one.
 *
 * "Cash" is method 'cash' at a branch; an account is method 'account' with
 * a shop_account_id.
 *
 * Also: Wallet Load Cash Out shortfalls now count as the customer's due.
 * Rows saved before this change are marked excluded_from_dues so existing
 * balances don't move until the owner decides how to read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->date('entry_date');
            $table->string('from_method', 20)->nullable();
            $table->foreignId('from_shop_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            $table->string('to_method', 20)->nullable();
            $table->foreignId('to_shop_account_id')->nullable()->constrained('shop_accounts')->nullOnDelete();
            // Opening and transfer: the amount. Count: the correction (counted minus expected), signed.
            $table->decimal('amount', 12, 2);
            $table->decimal('counted', 12, 2)->nullable();
            $table->decimal('expected', 12, 2)->nullable();
            $table->string('note', 255)->nullable();
            $table->string('status')->default('active');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'entry_date']);
        });

        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->boolean('excluded_from_dues')->default(false)->after('amount_paid');
        });

        DB::table('wallet_loads')
            ->where('direction', 'cash_out')
            ->where('payment_status', '!=', 'paid')
            ->update(['excluded_from_dues' => true]);
    }

    public function down(): void
    {
        Schema::table('wallet_loads', fn (Blueprint $table) => $table->dropColumn('excluded_from_dues'));
        Schema::dropIfExists('money_entries');
    }
};
