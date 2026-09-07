<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->foreignId('shop_account_id')->nullable()->after('bill_provider_id')->constrained()->nullOnDelete();
            $table->string('payment_status')->default('paid')->after('total');
            $table->decimal('amount_paid', 10, 2)->default(0)->after('payment_status');
        });

        // Every bill payment before this migration was collected from the
        // customer in full at the time of entry — there was no partial
        // concept — so backfill them as fully paid rather than newly unpaid.
        DB::table('bill_payments')->update(['amount_paid' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('bill_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_account_id');
            $table->dropColumn(['payment_status', 'amount_paid']);
        });
    }
};
