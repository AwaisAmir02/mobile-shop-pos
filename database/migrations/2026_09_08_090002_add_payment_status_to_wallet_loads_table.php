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
            $table->string('payment_status')->default('paid')->after('total');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        // Every wallet load before this migration was collected from the
        // customer in full at the time of entry — backfill as fully paid.
        DB::table('wallet_loads')->update(['amount_paid' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('wallet_loads', function (Blueprint $table) {
            $table->dropColumn(['payment_status', 'amount_paid']);
        });
    }
};
