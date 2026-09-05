<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->decimal('total_cost', 10, 2)->nullable()->after('quantity');
            $table->string('payment_status')->default('paid')->after('total_cost');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        // Historical entries predate payment tracking — treat them as
        // already settled rather than manufacturing phantom supplier debt.
        DB::table('stock_ins')->update(['payment_status' => 'paid']);
    }

    public function down(): void
    {
        Schema::table('stock_ins', function (Blueprint $table) {
            $table->dropColumn(['total_cost', 'payment_status', 'amount_paid']);
        });
    }
};
