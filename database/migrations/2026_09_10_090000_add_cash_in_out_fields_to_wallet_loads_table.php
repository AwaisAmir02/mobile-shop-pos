<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('direction')->default('cash_in')->after('shop_account_id');
            $table->boolean('fee_included_in_amount')->default(false)->after('discount');
            $table->decimal('net_amount', 10, 2)->default(0)->after('fee_included_in_amount');
        });

        // Historical loads all happened before Cash Out existed, and their
        // full amount moved into the wallet (fee was always on top) —
        // net_amount matches amount exactly for every pre-existing row.
        DB::table('wallet_loads')->update(['net_amount' => DB::raw('amount')]);

        // account_number predates Cash Out (where it doesn't apply) and was
        // required NOT NULL — doctrine/dbal isn't installed so it can't be
        // altered in place; recreate it as nullable, preserving existing
        // values.
        $existingAccountNumbers = DB::table('wallet_loads')->pluck('account_number', 'id');

        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->dropColumn('account_number');
        });

        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->string('account_number')->nullable()->after('account_name');
        });

        foreach ($existingAccountNumbers as $id => $accountNumber) {
            DB::table('wallet_loads')->where('id', $id)->update(['account_number' => $accountNumber]);
        }
    }

    public function down(): void
    {
        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['direction', 'fee_included_in_amount', 'net_amount']);
        });

        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->dropColumn('account_number');
        });

        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->string('account_number')->after('account_name');
        });
    }
};
