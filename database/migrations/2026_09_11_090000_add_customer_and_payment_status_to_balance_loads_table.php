<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->string('payment_status')->default('paid')->after('total');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        // Every balance load before this migration was cash-and-carry —
        // collected from the customer in full at the time of entry.
        DB::table('balance_loads')->update(['amount_paid' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('balance_loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['payment_status', 'amount_paid']);
        });
    }
};
