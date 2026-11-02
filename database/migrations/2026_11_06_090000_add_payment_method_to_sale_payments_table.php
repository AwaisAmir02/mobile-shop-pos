<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Purely descriptive: how a payment was made. No backfill — every
        // existing payment stays "not recorded" (both columns null).
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->string('payment_method', 20)->nullable()->after('payment_date');
            $table->foreignId('shop_account_id')->nullable()->after('payment_method')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_account_id');
            $table->dropColumn('payment_method');
        });
    }
};
