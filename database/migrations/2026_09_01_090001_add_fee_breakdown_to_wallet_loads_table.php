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
            $table->string('account_name')->nullable()->after('provider');
            $table->foreignId('shop_account_id')->nullable()->after('account_number')->constrained()->nullOnDelete();
            $table->decimal('fee', 10, 2)->default(0)->after('amount');
            $table->decimal('discount', 10, 2)->default(0)->after('fee');
            $table->decimal('total', 10, 2)->default(0)->after('discount');
        });

        // Historical loads predate the fee/discount breakdown — their total
        // collected from the customer was simply the amount loaded.
        DB::table('wallet_loads')->update(['total' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_account_id');
            $table->dropColumn(['account_name', 'fee', 'discount', 'total']);
        });
    }
};
